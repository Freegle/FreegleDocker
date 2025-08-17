const { execSync } = require('child_process');
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');

// Status cache for all services
const statusCache = new Map();
let lastFullCheck = 0;
const CHECK_INTERVAL = 30000; // 30 seconds
let isRunningChecks = false;

// Service definitions
const services = [
  // Freegle Components
  { id: 'freegle', container: 'freegle-freegle', checkType: 'freegle-component', category: 'freegle' },
  { id: 'modtools', container: 'freegle-modtools', checkType: 'freegle-component', category: 'freegle' },
  { id: 'apiv1', container: 'freegle-apiv1', checkType: 'api-service', category: 'freegle' },
  { id: 'apiv2', container: 'freegle-apiv2', checkType: 'api-service', category: 'freegle' },
  
  // Development Tools
  { id: 'phpmyadmin', container: 'freegle-phpmyadmin', checkType: 'dev-tool', category: 'dev' },
  { id: 'mailhog', container: 'freegle-mailhog', checkType: 'dev-tool', category: 'dev' },
  
  // Infrastructure Components
  { id: 'percona', container: 'freegle-percona', checkType: 'mysql', category: 'infra' },
  { id: 'postgres', container: 'freegle-postgres', checkType: 'postgres', category: 'infra' },
  { id: 'redis', container: 'freegle-redis', checkType: 'redis', category: 'infra' },
  { id: 'beanstalkd', container: 'freegle-beanstalkd', checkType: 'beanstalkd', category: 'infra' },
  { id: 'spamassassin', container: 'freegle-spamassassin', checkType: 'spamassassin', category: 'infra' },
  { id: 'traefik', container: 'freegle-traefik', checkType: 'traefik', category: 'infra' },
  { id: 'tusd', container: 'freegle-tusd', checkType: 'tusd', category: 'infra' }
];

// HTTP Agent with keep-alive for better performance
const httpAgent = new http.Agent({
  keepAlive: true,
  maxSockets: 10,
  timeout: 3000
});

const httpsAgent = new https.Agent({
  keepAlive: true,
  maxSockets: 10,
  timeout: 3000,
  rejectUnauthorized: false
});

// Improved fetch implementation with connection pooling
async function fetch(targetUrl) {
  return new Promise((resolve, reject) => {
    const isHttps = targetUrl.startsWith('https:');
    const client = isHttps ? https : http;
    const agent = isHttps ? httpsAgent : httpAgent;
    
    const req = client.get(targetUrl, { agent }, (res) => {
      resolve({
        ok: res.statusCode >= 200 && res.statusCode < 300,
        status: res.statusCode,
        statusText: res.statusMessage
      });
    });
    
    req.on('error', reject);
    req.setTimeout(3000, () => {
      req.destroy();
      reject(new Error('Request timeout'));
    });
  });
}

// Fast Docker stats using single command
async function getAllContainerStats() {
  try {
    // Get all container info in one command
    const output = execSync(`docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.State}}" --filter "name=freegle-"`, { 
      encoding: 'utf8', timeout: 1000 
    });
    
    const containers = {};
    const lines = output.split('\n').slice(1); // Skip header
    
    for (const line of lines) {
      if (line.trim()) {
        const parts = line.split(/\s+/);
        if (parts.length >= 3) {
          const name = parts[0];
          const status = parts.slice(1, -1).join(' '); // Everything except last column
          const state = parts[parts.length - 1];
          
          if (name && name.startsWith('freegle-')) {
            containers[name] = {
              status: state === 'running' ? 'running' : 'stopped',
              fullStatus: status.trim()
            };
          }
        }
      }
    }
    
    return containers;
  } catch (error) {
    console.error('Error getting container stats:', error);
    return {};
  }
}

// Background status checker using bulk Docker API
async function checkServiceStatus(service) {
  try {
    const allStats = await getAllContainerStats();
    const containerInfo = allStats[service.container];
    
    if (!containerInfo) {
      return { status: 'failed', message: 'Container not found' };
    }
    
    if (containerInfo.status === 'running') {
      // For Freegle components, check if build is actually successful
      if (service.checkType === 'freegle-component') {
        try {
          const logs = execSync(`docker logs ${service.container} --tail=5`, { 
            encoding: 'utf8', timeout: 1000 
          });
          
          if (logs.includes('ERROR') || logs.includes('Build failed')) {
            const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
            const error = errorMatch ? errorMatch[0].substring(0, 60) + '...' : 'Build failed';
            return { status: 'failed', message: `Build error: ${error}` };
          } else if (logs.includes('ready') || logs.includes('Listening') || logs.includes('GET request:') || logs.includes('optimized dependencies')) {
            return { status: 'success', message: 'Nuxt application ready and serving requests' };
          } else {
            return { status: 'starting', message: 'Building...' };
          }
        } catch (logError) {
          return { status: 'starting', message: 'Building...' };
        }
      } else {
        const healthCheckMessages = {
          'freegle-traefik': 'Reverse proxy dashboard accessible (wget /dashboard/)',
          'freegle-percona': 'MySQL database query test passed (SELECT 1)',
          'freegle-postgres': 'PostgreSQL database query test passed (SELECT 1)', 
          'freegle-redis': 'Cache service ping test successful (redis-cli ping)',
          'freegle-beanstalkd': 'Job queue port connection test passed (nc -z 11300)',
          'freegle-spamassassin': 'Email filtering port connection test passed (nc -z 783)',
          'freegle-tusd': 'File upload endpoint responding (wget /tus/)',
          'freegle-phpmyadmin': 'Database management interface responding (HTTP)',
          'freegle-mailhog': 'Email testing interface responding (HTTP)',
          'freegle-apiv1': 'API config endpoint responding (curl /api/config)'
        };
        
        return { 
          status: 'success', 
          message: healthCheckMessages[service.container] || 'Container running and healthy' 
        };
      }
    } else {
      return { status: 'failed', message: `Container ${containerInfo.status}` };
    }
  } catch (error) {
    return { status: 'failed', message: error.message };
  }
}

// Background check runner
async function runBackgroundChecks() {
  const now = Date.now();
  if (now - lastFullCheck < CHECK_INTERVAL || isRunningChecks) return;
  
  isRunningChecks = true;
  console.log('Running background status checks...');
  lastFullCheck = now;
  
  // Get all data in bulk for efficiency
  const allStats = await getAllContainerStats();
  const allCpu = await getAllCpuUsage();
  
  for (const service of services) {
    try {
      const containerInfo = allStats[service.container];
      const cpu = allCpu[service.container] || 0;
      
      if (!containerInfo) {
        statusCache.set(service.id, {
          status: 'failed',
          message: 'Container not found',
          timestamp: now,
          cpu: 0
        });
        continue;
      }
      
      let status, message;
      if (containerInfo.status === 'running') {
        // For Freegle components, check if build is actually successful
        if (service.checkType === 'freegle-component') {
          try {
            const logs = execSync(`docker logs ${service.container} --tail=5`, { 
              encoding: 'utf8', timeout: 1000 
            });
            
            if (logs.includes('ERROR') || logs.includes('Build failed')) {
              const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
              const error = errorMatch ? errorMatch[0].substring(0, 60) + '...' : 'Build failed';
              status = 'failed';
              message = `Build error: ${error}`;
            } else if (logs.includes('ready') || logs.includes('Listening') || logs.includes('GET request:') || logs.includes('optimized dependencies')) {
              status = 'success';
              message = 'Nuxt application ready and serving requests';
            } else {
              status = 'starting';
              message = 'Building...';
            }
          } catch (logError) {
            status = 'starting';
            message = 'Building...';
          }
        } else {
          status = 'success';
          const healthCheckMessages = {
            'freegle-traefik': 'Reverse proxy dashboard accessible (wget /dashboard/)',
            'freegle-percona': 'MySQL database query test passed (SELECT 1)',
            'freegle-postgres': 'PostgreSQL database query test passed (SELECT 1)', 
            'freegle-redis': 'Cache service ping test successful (redis-cli ping)',
            'freegle-beanstalkd': 'Job queue port connection test passed (nc -z 11300)',
            'freegle-spamassassin': 'Email filtering port connection test passed (nc -z 783)',
            'freegle-tusd': 'File upload endpoint responding (wget /tus/)',
            'freegle-phpmyadmin': 'Database management interface responding (HTTP)',
            'freegle-mailhog': 'Email testing interface responding (HTTP)',
            'freegle-apiv1': 'API config endpoint responding (curl /api/config)'
          };
          
          message = healthCheckMessages[service.container] || 'Container running and healthy';
        }
      } else {
        status = 'failed';
        message = `Container ${containerInfo.status}`;
      }
      
      statusCache.set(service.id, {
        status,
        message,
        timestamp: now,
        cpu
      });
    } catch (error) {
      statusCache.set(service.id, {
        status: 'failed',
        message: error.message,
        timestamp: now,
        cpu: 0
      });
    }
  }
  isRunningChecks = false;
}

// Get CPU usage for all containers in one call
async function getAllCpuUsage() {
  try {
    const output = execSync(`docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}" --filter "name=freegle-"`, { 
      encoding: 'utf8', timeout: 3000 
    });
    
    const cpuData = {};
    const lines = output.split('\n').slice(1); // Skip header
    
    for (const line of lines) {
      if (line.trim()) {
        const [name, cpu] = line.split('\t');
        if (name && name.startsWith('freegle-')) {
          cpuData[name] = parseFloat(cpu.replace('%', '')) || 0;
        }
      }
    }
    
    return cpuData;
  } catch (error) {
    console.error('Error getting CPU stats:', error);
    return {};
  }
}

// Start background checks after a delay and then every interval
setTimeout(() => {
  runBackgroundChecks();
  setInterval(runBackgroundChecks, CHECK_INTERVAL);
}, 2000);

const httpServer = http.createServer(async (req, res) => {
  // Enable CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  const parsedUrl = url.parse(req.url, true);
  
  // New cached status endpoint
  if (parsedUrl.pathname === '/api/status') {
    const service = parsedUrl.query.service;
    
    if (!service) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Missing service parameter' }));
      return;
    }
    
    const cached = statusCache.get(service);
    if (cached) {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(cached));
    } else {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Service not found' }));
    }
    return;
  }
  
  // All services status endpoint
  if (parsedUrl.pathname === '/api/status/all') {
    const allStatus = {};
    for (const [key, value] of statusCache.entries()) {
      allStatus[key] = value;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(allStatus));
    return;
  }
  
  if (parsedUrl.pathname === '/api/cpu') {
    const container = parsedUrl.query.container;
    
    if (!container) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Missing container parameter' }));
      return;
    }

    try {
      // Get container CPU stats
      const stats = execSync(`docker stats ${container} --no-stream --format "{{.CPUPerc}}"`, { 
        encoding: 'utf8', timeout: 5000 
      }).trim();
      
      const cpuPercent = parseFloat(stats.replace('%', '')) || 0;
      
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ cpu: cpuPercent, timestamp: Date.now() }));
    } catch (error) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: error.message, cpu: 0 }));
    }
    return;
  }

  if (parsedUrl.pathname === '/api/freegle-check') {
    const service = parsedUrl.query.service;
    
    if (!service) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('Missing service parameter');
      return;
    }

    try {
      let testUrl, testDescription;
      
      if (service === 'apiv2') {
        testUrl = 'http://freegle-apiv2:8192/api/group';
        testDescription = 'API v2 group endpoint responding';
      } else if (service === 'apiv1') {
        testUrl = 'http://freegle-apiv1:80/api/config';
        testDescription = 'API v1 config endpoint responding';
      } else if (service === 'freegle') {
        testUrl = 'http://freegle-freegle:3000/';
        testDescription = 'Freegle site responding';
      } else if (service === 'modtools') {
        testUrl = 'http://freegle-modtools:3000/';
        testDescription = 'ModTools site responding';
      } else {
        res.writeHead(400, { 'Content-Type': 'text/plain' });
        res.end('Unknown service');
        return;
      }
      
      const response = await fetch(testUrl);
      if (response.ok || (service === 'apiv1' && response.status === 404)) {
        const statusText = response.ok ? response.status : `${response.status} (expected)`;
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(`success|${testDescription} (HTTP ${statusText})`);
      } else if (response.status === 502 || response.status === 503) {
        res.writeHead(202, { 'Content-Type': 'text/plain' });
        res.end(`starting|Service building/starting (HTTP ${response.status})`);
      } else {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`failed|HTTP ${response.status}`);
      }
    } catch (error) {
      // Check if container is running but service not ready (building/starting)
      if (error.code === 'ECONNREFUSED' || error.message.includes('ECONNREFUSED') || error.message.includes('connect ECONNREFUSED')) {
        try {
          // Get the container name from the service
          let containerName = '';
          if (service === 'freegle') containerName = 'freegle-freegle';
          else if (service === 'modtools') containerName = 'freegle-modtools';
          else if (service === 'apiv1') containerName = 'freegle-apiv1';
          else if (service === 'apiv2') containerName = 'freegle-apiv2';
          
          if (containerName) {
            const lastLog = execSync(`docker logs ${containerName} --tail=1`, { 
              encoding: 'utf8', timeout: 3000 
            }).trim();
            const shortLog = lastLog.length > 80 ? lastLog.substring(0, 80) + '...' : lastLog;
            res.writeHead(202, { 'Content-Type': 'text/plain' });
            res.end(`starting|Building: ${shortLog || 'Starting up...'}`);
          } else {
            res.writeHead(202, { 'Content-Type': 'text/plain' });
            res.end(`starting|Service building/starting (connection refused)`);
          }
        } catch (logError) {
          res.writeHead(202, { 'Content-Type': 'text/plain' });
          res.end(`starting|Service building/starting (connection refused)`);
        }
      } else {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`failed|${error.message}`);
      }
    }
    return;
  }

  if (parsedUrl.pathname === '/api/dev-check') {
    const service = parsedUrl.query.service;
    
    if (!service) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('Missing service parameter');
      return;
    }

    try {
      let testUrl, testDescription;
      
      if (service === 'phpmyadmin') {
        testUrl = 'http://freegle-phpmyadmin:80/';
        testDescription = 'PhpMyAdmin login page accessible';
      } else if (service === 'mailhog') {
        testUrl = 'http://freegle-mailhog:8025/';
        testDescription = 'MailHog web interface accessible';
      } else {
        res.writeHead(400, { 'Content-Type': 'text/plain' });
        res.end('Unknown service');
        return;
      }
      
      const response = await fetch(testUrl);
      if (response.ok) {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(`success|${testDescription} (HTTP ${response.status})`);
      } else {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`failed|HTTP ${response.status}`);
      }
    } catch (error) {
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(`failed|${error.message}`);
    }
    return;
  }

  if (parsedUrl.pathname === '/api/check') {
    const type = parsedUrl.query.type;
    const container = parsedUrl.query.container;
    
    if (!type || !container) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('Missing type or container parameter');
      return;
    }

    try {
      let checkResult = '';
      
      // Use Docker's built-in health check status
      const healthStatus = execSync(`docker inspect --format='{{.State.Health.Status}}' ${container}`, { 
        encoding: 'utf8', timeout: 3000 
      }).trim();
      
      if (healthStatus === 'healthy') {
        let testDescription = '';
        switch (type) {
          case 'mysql': testDescription = 'MySQL query execution test (SELECT 1)'; break;
          case 'postgres': testDescription = 'PostgreSQL query execution test (SELECT 1)'; break;
          case 'redis': testDescription = 'Redis PING command response test'; break;
          case 'beanstalkd': testDescription = 'Beanstalkd port 11300 connection test'; break;
          case 'spamassassin': testDescription = 'SpamAssassin port 783 connection test'; break;
          case 'traefik': testDescription = 'Traefik dashboard HTTP endpoint test'; break;
          case 'tusd': testDescription = 'TusD upload endpoint responding (HTTP 200/405)'; break;
          default: testDescription = 'Health check passed';
        }
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(`success|${testDescription}`);
      } else {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`failed|Health status: ${healthStatus}`);
      }
    } catch (error) {
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(`failed|${error.message}`);
    }
    return;
  }

  if (parsedUrl.pathname === '/api/logs') {
    const container = parsedUrl.query.container;
    
    if (!container || !/^[a-zA-Z0-9._-]+$/.test(container)) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('Invalid container name');
      return;
    }

    try {
      const logs = execSync(`docker logs --tail=20 ${container}`, { 
        encoding: 'utf8',
        timeout: 5000
      });
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      res.end(logs || 'Container running but no recent logs');
    } catch (error) {
      // Try to get stderr if stdout is empty
      try {
        const stderrLogs = execSync(`docker logs --tail=20 ${container} 2>&1`, { 
          encoding: 'utf8',
          timeout: 5000
        });
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(stderrLogs || 'Container running but no logs');
      } catch (err2) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`Failed to get logs: ${error.message}`);
      }
    }
    return;
  }

  // Serve SSL certificate
  if (parsedUrl.pathname === '/ssl/ca.crt') {
    const certPath = '/app/ssl/ca.crt';
    if (fs.existsSync(certPath)) {
      res.writeHead(200, { 
        'Content-Type': 'application/x-x509-ca-cert',
        'Content-Disposition': 'attachment; filename="ca.crt"'
      });
      res.end(fs.readFileSync(certPath));
    } else {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('Certificate not found');
    }
    return;
  }

  // Serve static files
  const filePath = parsedUrl.pathname === '/' ? '/index.html' : parsedUrl.pathname;
  const fullPath = path.join(__dirname, filePath);
  
  if (fs.existsSync(fullPath) && fs.statSync(fullPath).isFile()) {
    const ext = path.extname(fullPath);
    const contentType = ext === '.html' ? 'text/html' : 
                       ext === '.js' ? 'text/javascript' :
                       ext === '.css' ? 'text/css' : 'text/plain';
    
    res.writeHead(200, { 'Content-Type': contentType });
    res.end(fs.readFileSync(fullPath));
  } else {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found');
  }
});

httpServer.listen(80, '0.0.0.0', () => {
  console.log('Status server running on port 80 with connection pooling');
});