const express = require('express');
const speakeasy = require('speakeasy');
const bcrypt = require('bcrypt');
const bodyParser = require('body-parser');
const cookieParser = require('cookie-parser');
const rateLimit = require('express-rate-limit');
const { createProxyMiddleware } = require('http-proxy-middleware');
const fs = require('fs').promises;
const path = require('path');
const http = require('http');
const os = require('os');

const app = express();
const PORT = process.env.PORT || 8084;
const ADMIN_KEY = process.env.YESTERDAY_ADMIN_KEY || 'changeme';
const USERS_FILE = process.env.USERS_FILE || '/data/2fa-users.json';
const WHITELIST_FILE = process.env.WHITELIST_FILE || '/data/ip-whitelist.json';
const WHITELIST_DURATION = 1 * 60 * 60 * 1000; // 1 hour
const ACCOUNT_LOCK_DURATION = 15 * 60 * 1000; // 15 minutes
const MAX_FAILED_ATTEMPTS = 5;

// Rate limiting for login attempts
const loginLimiter = rateLimit({
    windowMs: 15 * 60 * 1000, // 15 minutes
    max: 20, // Limit each IP to 20 requests per windowMs
    message: 'Too many login attempts from this IP, please try again later.',
    standardHeaders: true,
    legacyHeaders: false,
});

// IMPORTANT: Do NOT use body parsers globally - they break proxying
// Body parsers consume the request stream, preventing the proxy from forwarding it
// Instead, apply them only to specific routes that need them
app.use(cookieParser());

// In-memory cache
let users = {};
let ipWhitelist = {};

// Load users from file
async function loadUsers() {
    try {
        const data = await fs.readFile(USERS_FILE, 'utf8');
        users = JSON.parse(data);
        console.log(`Loaded ${Object.keys(users).length} users`);
    } catch (err) {
        if (err.code === 'ENOENT') {
            console.log('No users file found, starting with empty user list');
            users = {};
        } else {
            console.error('Error loading users:', err);
        }
    }
}

// Save users to file
async function saveUsers() {
    try {
        await fs.mkdir(path.dirname(USERS_FILE), { recursive: true });
        await fs.writeFile(USERS_FILE, JSON.stringify(users, null, 2));
        console.log('Users saved');
    } catch (err) {
        console.error('Error saving users:', err);
    }
}

// Load IP whitelist
async function loadWhitelist() {
    try {
        const data = await fs.readFile(WHITELIST_FILE, 'utf8');
        ipWhitelist = JSON.parse(data);
        cleanupWhitelist();
        console.log(`Loaded ${Object.keys(ipWhitelist).length} whitelisted IPs`);
    } catch (err) {
        if (err.code === 'ENOENT') {
            ipWhitelist = {};
        } else {
            console.error('Error loading whitelist:', err);
        }
    }
}

// Save IP whitelist
async function saveWhitelist() {
    try {
        await fs.mkdir(path.dirname(WHITELIST_FILE), { recursive: true });
        await fs.writeFile(WHITELIST_FILE, JSON.stringify(ipWhitelist, null, 2));
    } catch (err) {
        console.error('Error saving whitelist:', err);
    }
}

// Clean up expired whitelist entries
function cleanupWhitelist() {
    const now = Date.now();
    let cleaned = 0;

    for (const ip in ipWhitelist) {
        if (ipWhitelist[ip].expires < now) {
            delete ipWhitelist[ip];
            cleaned++;
        }
    }

    if (cleaned > 0) {
        console.log(`Cleaned up ${cleaned} expired whitelist entries`);
        saveWhitelist();
    }
}

// Check if IP is whitelisted
function isWhitelisted(ip) {
    cleanupWhitelist();
    return ipWhitelist[ip] && ipWhitelist[ip].expires > Date.now();
}

// Add IP to whitelist
async function whitelistIP(ip, username, permission) {
    ipWhitelist[ip] = {
        username,
        permission,
        expires: Date.now() + WHITELIST_DURATION,
        added: new Date().toISOString()
    };
    await saveWhitelist();
    console.log(`Whitelisted IP ${ip} for user ${username} (${permission})`);
}

// Get client IP
function getClientIP(req) {
    return req.headers['x-forwarded-for']?.split(',')[0].trim() ||
           req.headers['x-real-ip'] ||
           req.connection.remoteAddress;
}

// Check if user account is locked
function isAccountLocked(user) {
    if (user.lockedUntil && user.lockedUntil > Date.now()) {
        return true;
    }
    if (user.lockedUntil && user.lockedUntil <= Date.now()) {
        // Lock has expired, reset
        user.lockedUntil = null;
        user.failedAttempts = 0;
    }
    return false;
}

// Handle failed login attempt
async function handleFailedLogin(user) {
    user.failedAttempts = (user.failedAttempts || 0) + 1;

    if (user.failedAttempts >= MAX_FAILED_ATTEMPTS) {
        user.lockedUntil = Date.now() + ACCOUNT_LOCK_DURATION;
        console.log(`Account ${user.username} locked until ${new Date(user.lockedUntil).toISOString()}`);
    }

    await saveUsers();
}

// Handle successful login
async function handleSuccessfulLogin(user) {
    user.failedAttempts = 0;
    user.lockedUntil = null;
    user.lastLogin = new Date().toISOString();
    await saveUsers();
}

// Login page HTML
const loginPageHTML = `
<!DOCTYPE html>
<html>
<head>
    <title>Freegle Yesterday - Authentication</title>
    <link rel="icon" type="image/png" href="https://www.ilovefreegle.org/icon.png">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #5AB12E 0%, #4A9025 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo img {
            width: 120px;
            height: auto;
        }
        h1 {
            margin: 0 0 10px 0;
            color: #333;
            text-align: center;
            font-size: 24px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        input:focus {
            outline: none;
            border-color: #5AB12E;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #5AB12E 0%, #4A9025 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        button:hover {
            opacity: 0.9;
        }
        .error {
            color: #d32f2f;
            text-align: center;
            margin-top: 20px;
            padding: 10px;
            background: #ffebee;
            border-radius: 5px;
        }
        .info {
            color: #666;
            font-size: 14px;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="https://www.ilovefreegle.org/icon.png" alt="Freegle Logo">
        </div>
        <h1>Freegle Yesterday</h1>
        <div class="info" id="backup-status" style="margin-bottom: 20px;">Loading backup status...</div>
        <form method="POST" action="/auth/login">
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <input type="text" name="token" placeholder="6-digit code" pattern="[0-9]{6}" required>
            <button type="submit">Authenticate</button>
        </form>
        <div class="info">Use your password and Google Authenticator code</div>
        <div class="info" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0; font-size: 13px;">
            ℹ️ After successful authentication, your IP address will be whitelisted for 1 hour. You won't need to enter credentials again during this time.
        </div>
        {{ERROR}}
    </div>
    <script>
        fetch('/apiv2/latestmessage')
            .then(response => response.json())
            .then(data => {
                const statusEl = document.getElementById('backup-status');
                if (data.ret === 0 && data.latestmessage) {
                    const date = new Date(data.latestmessage);
                    statusEl.textContent = 'Latest message: ' + date.toLocaleString();
                    statusEl.style.color = '#4A9025';
                } else {
                    statusEl.textContent = 'Restoring...';
                    statusEl.style.color = '#666';
                }
            })
            .catch(err => {
                const statusEl = document.getElementById('backup-status');
                statusEl.textContent = 'Restoring...';
                statusEl.style.color = '#666';
            });
    </script>
</body>
</html>
`;

// Access denied page for non-admin users accessing restricted resources
const accessDeniedHTML = `
<!DOCTYPE html>
<html>
<head>
    <title>Access Denied</title>
    <link rel="icon" type="image/png" href="https://www.ilovefreegle.org/icon.png">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #5AB12E 0%, #4A9025 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 500px;
        }
        h1 {
            color: #d32f2f;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        a {
            color: #5AB12E;
            text-decoration: none;
            font-weight: 600;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Access Denied</h1>
        <p>This resource requires Administrator permissions. You are currently logged in with Support permissions.</p>
        <p>If you need access to this resource, please contact a system administrator.</p>
        <a href="/">← Back to Home</a>
    </div>
</body>
</html>
`;

// Detect Docker network subnets at runtime
let containerSubnets = [];
function detectContainerNetworks() {
    const interfaces = os.networkInterfaces();
    const subnets = [];

    for (const [name, addrs] of Object.entries(interfaces)) {
        for (const addr of addrs) {
            // Only process IPv4 addresses
            if (addr.family === 'IPv4' && !addr.internal) {
                // Extract network address from IP and netmask
                const ip = addr.address.split('.').map(Number);
                const netmask = addr.netmask.split('.').map(Number);
                const network = ip.map((octet, i) => octet & netmask[i]).join('.');
                const cidr = netmask.reduce((sum, octet) =>
                    sum + octet.toString(2).split('1').length - 1, 0);

                subnets.push({ network, cidr, netmask: addr.netmask });
                console.log(`Detected container network: ${network}/${cidr} (${name})`);
            }
        }
    }

    return subnets;
}

// Check if IP is within container's Docker networks
function isInternalDockerIP(ip) {
    // Always allow localhost
    if (ip.startsWith('127.') || ip.startsWith('::1') || ip === '::ffff:127.0.0.1') {
        return true;
    }

    // Remove IPv6 prefix if present
    const cleanIP = ip.replace(/^::ffff:/, '');

    // Check if IP is in any of our container's subnets
    const ipParts = cleanIP.split('.').map(Number);

    for (const subnet of containerSubnets) {
        const netmaskParts = subnet.netmask.split('.').map(Number);
        const networkParts = subnet.network.split('.').map(Number);

        // Check if IP is in this subnet
        const inSubnet = ipParts.every((octet, i) =>
            (octet & netmaskParts[i]) === networkParts[i]
        );

        if (inSubnet) {
            return true;
        }
    }

    return false;
}

// Middleware to check authentication
function requireAuth(req, res, next) {
    const clientIP = getClientIP(req);
    console.log(`[AUTH CHECK] Client IP: ${clientIP}, x-forwarded-for: ${req.headers['x-forwarded-for']}, x-real-ip: ${req.headers['x-real-ip']}, remoteAddress: ${req.connection.remoteAddress}`);

    // Bypass authentication for internal Docker network
    if (isInternalDockerIP(clientIP)) {
        req.userPermission = 'Admin';
        req.username = 'internal-docker';
        console.log(`[INTERNAL] Allowing internal Docker request from ${clientIP}`);
        return next();
    }

    if (isWhitelisted(clientIP)) {
        req.userPermission = ipWhitelist[clientIP].permission;
        req.username = ipWhitelist[clientIP].username;
        return next();
    }

    res.status(200).send(loginPageHTML.replace('{{ERROR}}', ''));
}

// Middleware to require Admin permission
function requireAdmin(req, res, next) {
    if (req.userPermission !== 'Admin') {
        return res.status(403).send(accessDeniedHTML);
    }
    next();
}

// Login page
app.get('/auth/login', (req, res) => {
    res.send(loginPageHTML.replace('{{ERROR}}', ''));
});

// Logout endpoint
app.get('/auth/logout', (req, res) => {
    const clientIP = getClientIP(req);

    // Remove from whitelist
    if (ipWhitelist[clientIP]) {
        delete ipWhitelist[clientIP];
        saveWhitelist();
        console.log(`User logged out from IP: ${clientIP}`);
    }

    // Redirect to login page
    res.redirect('/auth/login');
});

// Check current user endpoint
app.get('/auth/me', requireAuth, (req, res) => {
    res.json({
        username: req.username,
        permission: req.userPermission
    });
});

// Handle login
app.post('/auth/login', loginLimiter, bodyParser.json(), bodyParser.urlencoded({ extended: true }), async (req, res) => {
    const { username, password, token } = req.body;
    const clientIP = getClientIP(req);

    if (!username || !password || !token) {
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Username, password, and token required</div>'));
    }

    const user = users[username];
    if (!user) {
        console.log(`Login attempt for unknown user: ${username} from ${clientIP}`);
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Invalid credentials</div>'));
    }

    // Check if account is locked
    if (isAccountLocked(user)) {
        const unlockTime = new Date(user.lockedUntil).toLocaleTimeString();
        console.log(`Login attempt for locked account: ${username} from ${clientIP}`);
        return res.send(loginPageHTML.replace('{{ERROR}}', `<div class="error">Account temporarily locked due to multiple failed attempts. Try again after ${unlockTime}</div>`));
    }

    // Verify password
    const passwordMatch = await bcrypt.compare(password, user.password);
    if (!passwordMatch) {
        console.log(`Failed password for user: ${username} from ${clientIP}`);
        await handleFailedLogin(user);
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Invalid credentials</div>'));
    }

    // Verify TOTP token
    const verified = speakeasy.totp.verify({
        secret: user.secret,
        encoding: 'base32',
        token: token,
        window: 2 // Allow 2 time steps drift
    });

    if (verified) {
        await handleSuccessfulLogin(user);
        await whitelistIP(clientIP, username, user.permission || 'Support');
        console.log(`Successful login: ${username} from ${clientIP} (${user.permission || 'Support'})`);
        res.redirect('/');
    } else {
        console.log(`Failed 2FA for user: ${username} from ${clientIP}`);
        await handleFailedLogin(user);
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Invalid token</div>'));
    }
});

// Health check
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        users: Object.keys(users).length,
        whitelisted_ips: Object.keys(ipWhitelist).length
    });
});

// Public restore status endpoint - no auth required
// Used by ModTools dashboard to show backup status
app.get('/api/restore-status', createProxyMiddleware({
    target: 'http://yesterday-api:8082',
    changeOrigin: true
}));

// Admin endpoints (require ADMIN_KEY)
function requireAdminKey(req, res, next) {
    const key = req.headers['x-admin-key'] || req.query.admin_key;
    if (key !== ADMIN_KEY) {
        return res.status(403).json({ error: 'Invalid admin key' });
    }
    next();
}

// List users
app.get('/admin/users', requireAdminKey, (req, res) => {
    const userList = Object.keys(users).map(username => ({
        username,
        permission: users[username].permission || 'Support',
        created: users[username].created,
        lastLogin: users[username].lastLogin || null,
        failedAttempts: users[username].failedAttempts || 0,
        locked: isAccountLocked(users[username])
    }));
    res.json({ users: userList });
});

// Add user
app.post('/admin/users', requireAdminKey, bodyParser.json(), bodyParser.urlencoded({ extended: true }), async (req, res) => {
    const { username, password, permission } = req.body;

    if (!username || !password) {
        return res.status(400).json({ error: 'Username and password required' });
    }

    if (users[username]) {
        return res.status(409).json({ error: 'User already exists' });
    }

    const validPermissions = ['Support', 'Admin'];
    const userPermission = permission && validPermissions.includes(permission) ? permission : 'Support';

    // Hash password
    const hashedPassword = await bcrypt.hash(password, 10);

    // Generate TOTP secret
    const secret = speakeasy.generateSecret({
        name: `Yesterday (${username})`,
        issuer: 'Freegle Yesterday'
    });

    users[username] = {
        secret: secret.base32,
        password: hashedPassword,
        permission: userPermission,
        created: new Date().toISOString(),
        failedAttempts: 0,
        lockedUntil: null
    };

    await saveUsers();

    res.json({
        username,
        permission: userPermission,
        secret: secret.base32,
        qr_code_url: secret.otpauth_url,
        message: 'User created. Scan QR code with Google Authenticator'
    });
});

// Update user (change password or permission)
app.patch('/admin/users/:username', requireAdminKey, async (req, res) => {
    const { username } = req.params;
    const { password, permission } = req.body;

    if (!users[username]) {
        return res.status(404).json({ error: 'User not found' });
    }

    if (password) {
        users[username].password = await bcrypt.hash(password, 10);
    }

    if (permission) {
        const validPermissions = ['Support', 'Admin'];
        if (!validPermissions.includes(permission)) {
            return res.status(400).json({ error: 'Invalid permission. Must be Support or Admin' });
        }
        users[username].permission = permission;
    }

    await saveUsers();

    res.json({
        message: 'User updated',
        username,
        permission: users[username].permission
    });
});

// Delete user
app.delete('/admin/users/:username', requireAdminKey, async (req, res) => {
    const { username } = req.params;

    if (!users[username]) {
        return res.status(404).json({ error: 'User not found' });
    }

    delete users[username];
    await saveUsers();

    res.json({ message: 'User deleted', username });
});

// Middleware to check admin permissions for admin-only ports
function checkAdminPorts(req, res, next) {
    const port = req.headers['x-forwarded-port'];
    const adminOnlyPorts = ['8447', '8448']; // PHPMyAdmin, Mailhog

    if (adminOnlyPorts.includes(port) && req.userPermission !== 'Admin') {
        console.log(`Non-admin user ${req.username} attempted to access admin port ${port}`);
        return res.status(403).send(accessDeniedHTML);
    }

    next();
}

// Port-based routing - all traffic goes through this with requireAuth
app.use(requireAuth, checkAdminPorts, createProxyMiddleware({
    target: 'http://yesterday-index:80',
    changeOrigin: true,
    ws: true,  // Enable WebSocket support for hot reload
    timeout: 30000,  // 30 second timeout
    proxyTimeout: 30000,
    cookieDomainRewrite: {
        '*': 'yesterday.ilovefreegle.org'  // Rewrite cookie domain to external domain
    },
    cookiePathRewrite: false,  // Don't rewrite cookie paths
    router: (req) => {
        const port = req.headers['x-forwarded-port'];
        console.log(`[PORT ROUTING] Received X-Forwarded-Port: ${port}, Path: ${req.path}, User: ${req.username}`);

        // Route based on which port the request came in on
        switch(port) {
            case '8445':
                // Freegle Dev
                console.log(`→ Routing to Freegle Dev for user ${req.username}`);
                return 'http://freegle-freegle-dev:3002';
            case '8446':
                // ModTools Dev
                console.log(`→ Routing to ModTools Dev for user ${req.username}`);
                return 'http://freegle-modtools-dev:3000';
            case '8447':
                // PHPMyAdmin (Admin only - checked in middleware)
                console.log(`→ Routing to PHPMyAdmin for admin user ${req.username}`);
                return 'http://freegle-phpmyadmin:80';
            case '8448':
                // Mailhog (Admin only - checked in middleware)
                console.log(`→ Routing to Mailhog for admin user ${req.username}`);
                return 'http://freegle-mailhog:8025';
            case '8181':
                // Iznik API v1
                console.log(`→ Routing to Iznik API v1 for user ${req.username}`);
                return 'http://freegle-apiv1:80';
            case '8193':
                // Iznik API v2
                console.log(`→ Routing to Iznik API v2 for user ${req.username}`);
                return 'http://freegle-apiv2:8192';
            case '8444':
            case '443':
            default:
                // Yesterday UI or Yesterday backup API
                if (req.path.startsWith('/api/')) {
                    // All /api/ requests on port 8444 go to Yesterday backup API
                    console.log(`→ Routing to Yesterday API (backup management)`);
                    return 'http://yesterday-api:8082';
                } else {
                    console.log(`→ Routing to Yesterday UI`);
                    return 'http://yesterday-index:80';
                }
        }
    },
    onProxyReq: (proxyReq, req, res) => {
        // Log when proxy request starts
        req.proxyStartTime = Date.now();

        // Disable compression for phpMyAdmin to avoid JSON parsing issues
        if (req.headers['x-forwarded-port'] === '8447') {
            proxyReq.removeHeader('accept-encoding');
        }
    },
    onProxyRes: (proxyRes, req, res) => {
        // Log proxy response time
        const duration = Date.now() - (req.proxyStartTime || Date.now());
        if (duration > 5000) {
            console.log(`[SLOW PROXY] ${req.path} took ${duration}ms`);
        }
    },
    onError: (err, req, res) => {
        const duration = Date.now() - (req.proxyStartTime || Date.now());
        console.error(`Proxy error after ${duration}ms:`, err.message, 'Path:', req.path);
        res.status(502).send('Bad Gateway');
    }
}));

// Start server
async function start() {
    await loadUsers();
    await loadWhitelist();

    // Detect container networks for internal access bypass
    containerSubnets = detectContainerNetworks();
    console.log(`Internal Docker bypass enabled for ${containerSubnets.length} network(s)`);

    // Clean up whitelist every hour
    setInterval(cleanupWhitelist, 60 * 60 * 1000);

    app.listen(PORT, () => {
        console.log(`2FA Gateway listening on port ${PORT}`);
        console.log(`Admin key: ${ADMIN_KEY}`);
        console.log(`Authentication: Password + TOTP`);
        console.log(`Permission levels: Support (default), Admin`);
        console.log(`Brute force protection: Enabled (${MAX_FAILED_ATTEMPTS} attempts, ${ACCOUNT_LOCK_DURATION/60000} min lock)`);
    });
}

start();
