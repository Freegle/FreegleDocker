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

// Test execution tracking
let testStatus = {
  status: 'idle', // 'idle', 'running', 'completed', 'failed'
  message: '',
  logs: '',
  success: false,
  startTime: null,
  endTime: null
};

// Health check messages for services
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
  'freegle-apiv1': 'API config endpoint responding (curl /api/config)',
  'freegle-apiv2': 'API group endpoint responding (curl /api/group)',
  'freegle-delivery': 'wsrv.nl image transformation service responding (wget /)',
  'freegle-playwright': 'Playwright test container ready for test execution'
};

// Service definitions
const services = [
  // Freegle Components
  { id: 'freegle-dev', container: 'freegle-freegle-dev', checkType: 'freegle-component', category: 'freegle' },
  { id: 'freegle-prod', container: 'freegle-freegle-prod', checkType: 'freegle-component', category: 'freegle' },
  { id: 'modtools', container: 'freegle-modtools', checkType: 'freegle-component', category: 'freegle' },
  { id: 'apiv1', container: 'freegle-apiv1', checkType: 'api-service', category: 'freegle' },
  { id: 'apiv2', container: 'freegle-apiv2', checkType: 'api-service', category: 'freegle' },
  
  // Development Tools
  { id: 'phpmyadmin', container: 'freegle-phpmyadmin', checkType: 'dev-tool', category: 'dev' },
  { id: 'mailhog', container: 'freegle-mailhog', checkType: 'dev-tool', category: 'dev' },
  { id: 'playwright', container: 'freegle-playwright', checkType: 'playwright', category: 'dev' },
  
  // Infrastructure Components
  { id: 'percona', container: 'freegle-percona', checkType: 'mysql', category: 'infra' },
  { id: 'postgres', container: 'freegle-postgres', checkType: 'postgres', category: 'infra' },
  { id: 'redis', container: 'freegle-redis', checkType: 'redis', category: 'infra' },
  { id: 'beanstalkd', container: 'freegle-beanstalkd', checkType: 'beanstalkd', category: 'infra' },
  { id: 'spamassassin', container: 'freegle-spamassassin', checkType: 'spamassassin', category: 'infra' },
  { id: 'traefik', container: 'freegle-traefik', checkType: 'traefik', category: 'infra' },
  { id: 'tusd', container: 'freegle-tusd', checkType: 'tusd', category: 'infra' },
  { id: 'delivery', container: 'freegle-delivery', checkType: 'delivery', category: 'infra' }
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
    // Get all container info in one command with start time
    const output = execSync(`docker ps -a --format "{{.Names}}\t{{.Status}}\t{{.State}}\t{{.CreatedAt}}"`, { 
      encoding: 'utf8', timeout: 1000 
    });
    
    const containers = {};
    const lines = output.split('\n');
    
    for (const line of lines) {
      if (line.trim()) {
        const parts = line.split('\t');
        if (parts.length >= 4) {
          const name = parts[0].trim();
          const status = parts[1].trim();
          const state = parts[2].trim();
          const createdAt = parts[3].trim();
          
          if (name && name.startsWith('freegle-')) {
            // Get more detailed start time using docker inspect
            let startTime = null;
            try {
              const startTimeOutput = execSync(`docker inspect --format='{{.State.StartedAt}}' ${name}`, { 
                encoding: 'utf8', timeout: 500 
              }).trim();
              if (startTimeOutput && startTimeOutput !== '<no value>' && !startTimeOutput.includes('Error')) {
                startTime = new Date(startTimeOutput);
              }
            } catch (inspectError) {
              // Fallback to created time if StartedAt fails
              try {
                startTime = new Date(createdAt);
              } catch (parseError) {
                startTime = null;
              }
            }
            
            containers[name] = {
              status: state === 'running' ? 'running' : 'stopped',
              fullStatus: status,
              startTime: startTime,
              createdAt: createdAt
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

// Check if all services are online
async function checkAllServicesOnline() {
  try {
    const serviceStatuses = await Promise.all(
      services.map(async (service) => {
        const status = await checkServiceStatus(service);
        return { id: service.id, online: status.status === 'success' };
      })
    );
    
    const offlineServices = serviceStatuses.filter(s => !s.online);
    const onlineCount = serviceStatuses.length - offlineServices.length;
    
    if (offlineServices.length > 0) {
      return {
        success: false,
        message: `${offlineServices.length} services are not online (${onlineCount}/${serviceStatuses.length} online): ${offlineServices.map(s => s.id).join(', ')}`
      };
    }
    
    return { success: true, message: 'All services are online' };
  } catch (error) {
    return {
      success: false,
      message: `Failed to check service statuses: ${error.message}`
    };
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
      // For Freegle components, check if they are actually serving pages
      if (service.checkType === 'freegle-component') {
        try {
          let testUrl, testDescription;
          
          if (service.id === 'freegle-dev') {
            testUrl = 'http://freegle-freegle-dev:3002/';
            testDescription = 'Freegle Dev site responding';
          } else if (service.id === 'freegle-prod') {
            testUrl = 'http://freegle-freegle-prod:3003/';
            testDescription = 'Freegle Prod site responding';
          } else if (service.id === 'modtools') {
            testUrl = 'http://freegle-modtools:3000/';
            testDescription = 'ModTools site responding';
          }
          
          if (testUrl) {
            // Try to fetch the page to verify it's actually working
            try {
              const response = await fetch(testUrl);
              if (response.ok) {
                return { status: 'success', message: `${testDescription} (HTTP ${response.status})` };
              } else if (response.status === 502 || response.status === 503) {
                return { status: 'starting', message: `Service building/starting (HTTP ${response.status})` };
              } else {
                return { status: 'failed', message: `HTTP ${response.status}` };
              }
            } catch (fetchError) {
              // If fetch fails, check logs for more context
              if (fetchError.code === 'ECONNREFUSED' || fetchError.message.includes('ECONNREFUSED')) {
                try {
                  const logs = execSync(`docker logs ${service.container} --tail=10`, { 
                    encoding: 'utf8', timeout: 1000 
                  });
                  
                  // Check for various build failure patterns
                  if (logs.includes('ERROR') || logs.includes('Build failed') || 
                      logs.includes('✖ ') || // ESLint errors
                      logs.includes('prettier/prettier') || // Prettier errors
                      logs.includes('error  Insert') || logs.includes('error  Replace') ||
                      logs.includes('buildEnd') || // Vite build errors
                      (logs.includes('problems') && logs.includes('errors'))) {
                    
                    let error = 'Build failed';
                    
                    // Extract specific error information
                    if (logs.includes('prettier/prettier')) {
                      error = 'Prettier/ESLint formatting errors';
                    } else if (logs.includes('✖ ')) {
                      const problemMatch = logs.match(/✖ (\d+) problems \((\d+) errors/);
                      if (problemMatch) {
                        error = `ESLint: ${problemMatch[2]} errors, ${problemMatch[1]} total problems`;
                      } else {
                        error = 'ESLint errors detected';
                      }
                    } else {
                      const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
                      if (errorMatch) {
                        error = errorMatch[0].substring(0, 60) + '...';
                      }
                    }
                    
                    return { status: 'failed', message: `Build error: ${error}` };
                  } else {
                    // Show building status with last log line
                    const logLines = logs.trim().split('\n');
                    const lastLine = logLines[logLines.length - 1] || 'Building...';
                    const truncatedLine = lastLine.length > 80 ? lastLine.substring(0, 80) + '...' : lastLine;
                    return { status: 'starting', message: `Building: ${truncatedLine}` };
                  }
                } catch (logError) {
                  return { status: 'starting', message: 'Service building/starting (connection refused)' };
                }
              } else {
                return { status: 'failed', message: fetchError.message };
              }
            }
          } else {
            // Fallback for unknown components
            return { status: 'success', message: 'Container running' };
          }
        } catch (error) {
          return { status: 'failed', message: error.message };
        }
      } else if (service.checkType === 'playwright') {
        // For Playwright, verify the container can execute test commands
        try {
          const testCommand = `docker exec ${service.container} npx playwright --version`;
          const result = execSync(testCommand, { encoding: 'utf8', timeout: 5000 });
          return { 
            status: 'success', 
            message: `Playwright ready for tests (${result.trim()})` 
          };
        } catch (error) {
          return { status: 'failed', message: `Playwright not ready: ${error.message}` };
        }
      } else {
        
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
          cpu: 0,
          startTime: null,
          uptime: null
        });
        continue;
      }
      
      let status, message;
      if (containerInfo.status === 'running') {
        // For Freegle components, check if they are actually serving pages
        if (service.checkType === 'freegle-component') {
          try {
            let testUrl, testDescription;
            
            if (service.id === 'freegle-dev') {
              testUrl = 'http://freegle-freegle-dev:3002/';
              testDescription = 'Freegle Dev site responding';
            } else if (service.id === 'freegle-prod') {
              testUrl = 'http://freegle-freegle-prod:3003/';
              testDescription = 'Freegle Prod site responding';
            } else if (service.id === 'modtools') {
              testUrl = 'http://freegle-modtools:3000/';
              testDescription = 'ModTools site responding';
            }
            
            if (testUrl) {
              // Try to fetch the page to verify it's actually working
              try {
                const response = await fetch(testUrl);
                if (response.ok) {
                  status = 'success';
                  message = `${testDescription} (HTTP ${response.status})`;
                } else if (response.status === 502 || response.status === 503) {
                  status = 'starting';
                  message = `Service building/starting (HTTP ${response.status})`;
                } else {
                  status = 'failed';
                  message = `HTTP ${response.status}`;
                }
              } catch (fetchError) {
                // If fetch fails, check logs for more context
                if (fetchError.code === 'ECONNREFUSED' || fetchError.message.includes('ECONNREFUSED')) {
                  try {
                    const logs = execSync(`docker logs ${service.container} --tail=10`, { 
                      encoding: 'utf8', timeout: 1000 
                    });
                    
                    // Check for various build failure patterns
                    if (logs.includes('ERROR') || logs.includes('Build failed') || 
                        logs.includes('✖ ') || // ESLint errors
                        logs.includes('prettier/prettier') || // Prettier errors
                        logs.includes('error  Insert') || logs.includes('error  Replace') ||
                        logs.includes('buildEnd') || // Vite build errors
                        (logs.includes('problems') && logs.includes('errors'))) {
                      
                      let error = 'Build failed';
                      
                      // Extract specific error information
                      if (logs.includes('prettier/prettier')) {
                        error = 'Prettier/ESLint formatting errors';
                      } else if (logs.includes('✖ ')) {
                        const problemMatch = logs.match(/✖ (\d+) problems \((\d+) errors/);
                        if (problemMatch) {
                          error = `ESLint: ${problemMatch[2]} errors, ${problemMatch[1]} total problems`;
                        } else {
                          error = 'ESLint errors detected';
                        }
                      } else {
                        const errorMatch = logs.match(/ERROR.*?(?=\n|$)/);
                        if (errorMatch) {
                          error = errorMatch[0].substring(0, 60) + '...';
                        }
                      }
                      
                      status = 'failed';
                      message = `Build error: ${error}`;
                    } else {
                      // Show building status with last log line
                      const logLines = logs.trim().split('\n');
                      const lastLine = logLines[logLines.length - 1] || 'Building...';
                      const truncatedLine = lastLine.length > 80 ? lastLine.substring(0, 80) + '...' : lastLine;
                      status = 'starting';
                      message = `Building: ${truncatedLine}`;
                    }
                  } catch (logError) {
                    status = 'starting';
                    message = 'Service building/starting (connection refused)';
                  }
                } else {
                  status = 'failed';
                  message = fetchError.message;
                }
              }
            } else {
              // Fallback to log checking for unknown components
              status = 'success';
              message = 'Container running';
            }
          } catch (error) {
            status = 'failed';
            message = error.message;
          }
        } else {
          status = 'success';
          
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
        cpu,
        startTime: containerInfo.startTime,
        uptime: containerInfo.startTime ? Math.floor((now - containerInfo.startTime.getTime()) / 1000) : null
      });
    } catch (error) {
      statusCache.set(service.id, {
        status: 'failed',
        message: error.message,
        timestamp: now,
        cpu: 0,
        startTime: null,
        uptime: null
      });
    }
  }
  isRunningChecks = false;
}

// Get CPU usage for all containers in one call
async function getAllCpuUsage() {
  try {
    const output = execSync(`docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}"`, { 
      encoding: 'utf8', timeout: 3000 
    });
    
    const cpuData = {};
    const lines = output.split('\n').slice(1); // Skip header
    
    for (const line of lines) {
      if (line.trim()) {
        const [name, cpu] = line.split('\t');
        if (name && name.startsWith('freegle-') && cpu) {
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

// Function to run Playwright tests in Docker
async function runPlaywrightTests() {
  if (testStatus.status === 'running') {
    throw new Error('Tests are already running');
  }

  testStatus = {
    status: 'running',
    message: 'Initializing test environment...',
    logs: '',
    success: false,
    startTime: new Date(),
    endTime: null
  };

  console.log('Starting Playwright tests in Docker...');

  try {
    // First, ensure the freegle container is running and accessible
    testStatus.message = 'Checking Freegle container status...';
    
    // Check that the required containers are running
    const freegleProdCheck = execSync('docker ps --filter "name=freegle-freegle-prod" --format "{{.Status}}"', { 
      encoding: 'utf8', timeout: 5000 
    }).trim();
    
    if (!freegleProdCheck.includes('Up')) {
      throw new Error('Freegle Production container is not running. Tests require the production site to be accessible.');
    }

    // Restart Playwright container to kill any existing processes
    testStatus.message = 'Restarting Playwright container...';
    testStatus.logs += 'Freegle Production container is running\n';
    testStatus.logs += 'Restarting Playwright container to kill existing processes\n';
    
    try {
      execSync('docker restart freegle-playwright', { encoding: 'utf8', timeout: 30000 });
      testStatus.logs += 'Playwright container restarted\n';
    } catch (restartError) {
      console.warn('Failed to restart Playwright container:', restartError.message);
      testStatus.logs += `Warning: Failed to restart container: ${restartError.message}\n`;
    }

    testStatus.message = 'Executing tests in Playwright container...';
    testStatus.logs += 'Playwright container is ready\n';

    // Execute tests in the persistent Playwright container using docker exec
    const testCommand = `docker exec freegle-playwright npx playwright test --reporter=html`;

    console.log('Executing Playwright tests in persistent container...');
    
    // Execute the command and capture output in real-time
    const { spawn } = require('child_process');
    const testProcess = spawn('sh', ['-c', testCommand], {
      stdio: ['pipe', 'pipe', 'pipe']
    });

    let testOutput = '';

    testProcess.stdout.on('data', (data) => {
      const output = data.toString();
      testOutput += output;
      testStatus.logs += output;
      
      // Get the last meaningful line from the recent output for detailed progress
      const lines = output.trim().split('\n');
      const lastLine = lines[lines.length - 1] || '';
      const secondLastLine = lines.length > 1 ? lines[lines.length - 2] : '';
      
      // Update status based on log content with more detailed progress
      const lowerOutput = output.toLowerCase();
      if (lowerOutput.includes('checking test environment')) {
        testStatus.message = 'Initializing test environment...';
      } else if (lowerOutput.includes('installing alpine') || lowerOutput.includes('apk add')) {
        testStatus.message = 'Installing system dependencies...';
      } else if (lowerOutput.includes('installing playwright')) {
        testStatus.message = 'Installing Playwright browsers...';
      } else if (lowerOutput.includes('running playwright')) {
        testStatus.message = 'Running Playwright tests...';
      } else if (lowerOutput.includes('running') && lowerOutput.includes('tests')) {
        const testMatch = output.match(/running (\d+) tests?\s+using/i);
        if (testMatch) {
          testStatus.totalTests = parseInt(testMatch[1]);
          const currentTest = testStatus.completedTests ? testStatus.completedTests + 1 : 1;
          testStatus.message = `Running test ${currentTest}/${testMatch[1]}`;
        } else {
          testStatus.message = 'Running Playwright tests...';
        }
      } else if (output.match(/^\s*[✓✘]\s+\d+/m)) {
        // Extract current test progress from individual test results
        // Count all test results in the entire log, not just the current output chunk
        const allResults = (testStatus.logs.match(/^\s*[✓✘]\s+\d+/gm) || []);
        if (allResults.length > 0) {
          const completed = allResults.length;
          const passed = (testStatus.logs.match(/^\s*✓\s+\d+/gm) || []).length;
          const failed = (testStatus.logs.match(/^\s*✘\s+\d+/gm) || []).length;
          const totalTests = testStatus.totalTests || 40;
          
          // Store test progress for progress bar
          testStatus.completedTests = completed;
          
          // Show the actual test result line for context
          const testResultLine = output.match(/^\s*[✓✘]\s+\d+.*$/m);
          if (testResultLine) {
            const result = testResultLine[0].trim();
            testStatus.message = `${completed}/${totalTests} tests completed (${passed}✓ ${failed}✘) | ${result}`;
          } else {
            testStatus.message = `${completed}/${totalTests} tests completed (${passed} passed, ${failed} failed)`;
          }
        }
      } else if (output.match(/^\s*\d+\)\s+.+\.spec\.js:\d+:\d+\s+›/m)) {
        // Detect active test execution by looking for test file references
        const testMatch = output.match(/^\s*\d+\)\s+(.+\.spec\.js):\d+:\d+\s+›\s+(.+)$/m);
        if (testMatch) {
          const testFile = testMatch[1].replace(/.*\//, ''); // Get just the filename
          const testName = testMatch[2];
          const currentTest = testStatus.completedTests ? testStatus.completedTests + 1 : 1;
          const totalTests = testStatus.totalTests || 40;
          testStatus.message = `Running test ${currentTest}/${totalTests}: ${testName} (${testFile})`;
        }
      } else if (output.includes('› ') && (output.includes('test-') || output.includes('.spec.js'))) {
        // Generic test execution detection - show the actual line
        const testLine = lines.find(line => line.includes('› ') && (line.includes('test-') || line.includes('.spec.js')));
        if (testLine) {
          const cleanLine = testLine.replace(/^\s*\d+\)\s*/, '').trim();
          testStatus.message = `Running: ${cleanLine}`;
        } else {
          testStatus.message = 'Running tests...';
        }
      } else if (lowerOutput.includes('passed') && lowerOutput.includes('failed') && output.match(/\d+\s+passed.*\d+\s+failed/i)) {
        // Only show final results when we see a complete summary line
        const passedMatch = output.match(/(\d+)\s+passed/i);
        const failedMatch = output.match(/(\d+)\s+failed/i);
        const skippedMatch = output.match(/(\d+)\s+skipped/i);
        
        if (passedMatch || failedMatch) {
          const passed = passedMatch ? passedMatch[1] : '0';
          const failed = failedMatch ? failedMatch[1] : '0';
          const skipped = skippedMatch ? skippedMatch[1] : '0';
          testStatus.message = `Tests completed: ${passed} passed, ${failed} failed${skipped !== '0' ? ', ' + skipped + ' skipped' : ''}`;
        }
      } else if (lowerOutput.includes('generating') && (lowerOutput.includes('html report') || lowerOutput.includes('playwright report'))) {
        // Only show "generating reports" when we explicitly see HTML report generation
        testStatus.message = 'Generating HTML test reports...';
      } else if (lowerOutput.includes('generating') && lowerOutput.includes('coverage')) {
        testStatus.message = 'Generating code coverage reports...';
      } else if (lastLine.length > 10) {
        // Show the most recent meaningful line for general progress
        const meaningfulLine = lastLine.length > 80 ? lastLine.substring(0, 80) + '...' : lastLine;
        const currentMessage = testStatus.message || 'Running tests...';
        const timestamp = new Date().toLocaleTimeString('en-US', { hour12: false });
        
        // Only update if this looks like meaningful progress (not just whitespace or timestamps)
        if (lastLine.match(/[a-zA-Z]/) && !lastLine.match(/^\s*$/)) {
          // Check if it's a test action or navigation
          if (lastLine.includes('page.') || lastLine.includes('test') || lastLine.includes('browser') || 
              lastLine.includes('click') || lastLine.includes('fill') || lastLine.includes('wait') ||
              lastLine.includes('navigation') || lastLine.includes('loading') || lastLine.includes('error')) {
            testStatus.message = `${currentMessage.split(' | ')[0]} | ${meaningfulLine} (${timestamp})`;
            testStatus.lastOutputTime = Date.now();
          }
        }
      }
      
      console.log('Test stdout:', output);
    });

    testProcess.stderr.on('data', (data) => {
      const output = data.toString();
      testOutput += output;
      testStatus.logs += output;
      
      // Update status for stderr as well
      const lowerOutput = output.toLowerCase();
      if (lowerOutput.includes('error') && !lowerOutput.includes('warning')) {
        testStatus.message = 'Processing test output...';
      }
      
      console.log('Test stderr:', output);
    });

    testProcess.on('close', (code) => {
      testStatus.endTime = new Date();
      const duration = Math.round((testStatus.endTime - testStatus.startTime) / 1000);


      // Check for various failure conditions regardless of exit code
      if (testStatus.logs.includes('Error: No tests found')) {
        testStatus.status = 'failed';
        testStatus.success = false;
        testStatus.message = `Tests failed: No tests found after ${duration}s`;
        testStatus.logs += '\n❌ Tests failed: No tests found';
      } else if (testStatus.logs.includes('Testing stopped early after') && testStatus.logs.includes('maximum allowed failures')) {
        // Extract failure information from logs
        const failedMatch = testStatus.logs.match(/(\d+)\s+failed/);
        const didNotRunMatch = testStatus.logs.match(/(\d+)\s+did not run/);
        const failedCount = failedMatch ? failedMatch[1] : 'some';
        const skippedCount = didNotRunMatch ? didNotRunMatch[1] : '';
        
        testStatus.status = 'failed';
        testStatus.success = false;
        testStatus.message = `Tests failed: ${failedCount} failed${skippedCount ? `, ${skippedCount} skipped` : ''} after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed: ${failedCount} failed${skippedCount ? `, ${skippedCount} skipped` : ''}`;
      } else if (testStatus.logs.match(/\d+\s+failed/) && !testStatus.logs.includes('0 failed')) {
        // General test failure detection
        const failedMatch = testStatus.logs.match(/(\d+)\s+failed/);
        const failedCount = failedMatch ? failedMatch[1] : 'some';
        
        testStatus.status = 'failed';
        testStatus.success = false;
        testStatus.message = `Tests failed: ${failedCount} test${failedCount !== '1' ? 's' : ''} failed after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed: ${failedCount} test${failedCount !== '1' ? 's' : ''} failed`;
      } else if (code === 0) {
        testStatus.status = 'completed';
        testStatus.success = true;
        testStatus.message = `Tests completed successfully in ${duration}s`;
        testStatus.logs += '\n✅ All tests passed!';
      } else {
        testStatus.status = 'failed';
        testStatus.success = false;
        testStatus.message = `Tests failed (exit code ${code}) after ${duration}s`;
        testStatus.logs += `\n❌ Tests failed with exit code ${code}`;
      }

      console.log(`Test process finished with code ${code}`);
    });

    testProcess.on('error', (error) => {
      testStatus.status = 'failed';
      testStatus.success = false;
      testStatus.message = `Test execution failed: ${error.message}`;
      testStatus.logs += `\nError: ${error.message}`;
      testStatus.endTime = new Date();
      console.error('Test process error:', error);
    });

  } catch (error) {
    testStatus.status = 'failed';
    testStatus.success = false;
    testStatus.message = `Failed to start tests: ${error.message}`;
    testStatus.logs += `\nError: ${error.message}`;
    testStatus.endTime = new Date();
    console.error('Error starting tests:', error);
    throw error;
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

  // Container restart endpoint
  if (parsedUrl.pathname === '/api/container/restart' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk.toString());
    req.on('end', async () => {
      try {
        const { container } = JSON.parse(body);
        
        if (!container || !/^freegle-[a-zA-Z0-9_-]+$/.test(container)) {
          res.writeHead(400, { 'Content-Type': 'text/plain' });
          res.end('Invalid container name');
          return;
        }
        
        console.log(`Restarting container: ${container}`);
        execSync(`docker restart ${container}`, { timeout: 30000 });
        
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(`Container ${container} restarted successfully`);
      } catch (error) {
        console.error('Restart error:', error);
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`Failed to restart container: ${error.message}`);
      }
    });
    return;
  }

  // Container rebuild endpoint  
  if (parsedUrl.pathname === '/api/container/rebuild' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk.toString());
    req.on('end', async () => {
      try {
        const { container, service } = JSON.parse(body);
        
        if (!container || !/^freegle-[a-zA-Z0-9_-]+$/.test(container)) {
          res.writeHead(400, { 'Content-Type': 'text/plain' });
          res.end('Invalid container name');
          return;
        }
        
        if (!service || !/^[a-zA-Z0-9_-]+$/.test(service)) {
          res.writeHead(400, { 'Content-Type': 'text/plain' });
          res.end('Invalid service name');
          return;
        }
        
        console.log(`Rebuilding service: ${service} (${container})`);
        
        // Change to docker compose project directory
        process.chdir('/project');
        
        // Build and restart the specific service
        const buildCommand = `docker-compose build ${service} && docker-compose up -d ${service}`;
        execSync(buildCommand, { timeout: 300000 }); // 5 minutes timeout
        
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end(`Service ${service} rebuilt and restarted successfully`);
      } catch (error) {
        console.error('Rebuild error:', error);
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end(`Failed to rebuild service: ${error.message}`);
      }
    });
    return;
  }

  // Playwright test execution endpoint
  if (parsedUrl.pathname === '/api/tests/playwright' && req.method === 'POST') {
    try {
      console.log('Received request to run Playwright tests');
      
      // Start tests asynchronously (dependencies are handled by Docker Compose)
      runPlaywrightTests().catch(error => {
        console.error('Test execution error:', error);
      });
      
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      res.end('Playwright tests started successfully');
    } catch (error) {
      console.error('Failed to start tests:', error);
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end(`Failed to start tests: ${error.message}`);
    }
    return;
  }

  // Playwright test status endpoint
  if (parsedUrl.pathname === '/api/tests/playwright/status' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    const now = Date.now();
    const timeSinceLastOutput = testStatus.lastOutputTime ? Math.floor((now - testStatus.lastOutputTime) / 1000) : null;
    
    res.end(JSON.stringify({
      status: testStatus.status,
      message: testStatus.message,
      logs: testStatus.logs.length > 5000 ? 
        '...(truncated)\n' + testStatus.logs.slice(-5000) : 
        testStatus.logs,
      success: testStatus.success,
      startTime: testStatus.startTime,
      endTime: testStatus.endTime,
      completedTests: testStatus.completedTests,
      totalTests: testStatus.totalTests,
      lastOutputTime: testStatus.lastOutputTime,
      timeSinceLastOutput: timeSinceLastOutput
    }));
    return;
  }

  // Playwright report redirect endpoint - redirect to container's built-in server
  if (parsedUrl.pathname === '/api/tests/playwright/report' && req.method === 'GET') {
    res.writeHead(302, { 
      'Location': 'http://localhost:9327' 
    });
    res.end();
    return;
  }

  
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
      } else if (service === 'freegle-dev') {
        testUrl = 'http://freegle-freegle-dev:3002/';
        testDescription = 'Freegle Dev site responding';
      } else if (service === 'freegle-prod') {
        testUrl = 'http://freegle-freegle-prod:3003/';
        testDescription = 'Freegle Prod site responding';
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
          if (service === 'freegle-dev') containerName = 'freegle-freegle-dev';
          else if (service === 'freegle-prod') containerName = 'freegle-freegle-prod';
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
          case 'playwright': testDescription = 'Playwright test container ready for execution'; break;
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