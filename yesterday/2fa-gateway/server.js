const express = require('express');
const speakeasy = require('speakeasy');
const bodyParser = require('body-parser');
const cookieParser = require('cookie-parser');
const fs = require('fs').promises;
const path = require('path');
const http = require('http');

const app = express();
const PORT = process.env.PORT || 8084;
const ADMIN_KEY = process.env.YESTERDAY_ADMIN_KEY || 'changeme';
const USERS_FILE = process.env.USERS_FILE || '/data/2fa-users.json';
const WHITELIST_FILE = process.env.WHITELIST_FILE || '/data/ip-whitelist.json';
const WHITELIST_DURATION = 24 * 60 * 60 * 1000; // 24 hours

app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));
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
async function whitelistIP(ip, username) {
    ipWhitelist[ip] = {
        username,
        expires: Date.now() + WHITELIST_DURATION,
        added: new Date().toISOString()
    };
    await saveWhitelist();
    console.log(`Whitelisted IP ${ip} for user ${username}`);
}

// Get client IP
function getClientIP(req) {
    return req.headers['x-forwarded-for']?.split(',')[0].trim() ||
           req.headers['x-real-ip'] ||
           req.connection.remoteAddress;
}

// Login page HTML
const loginPageHTML = `
<!DOCTYPE html>
<html>
<head>
    <title>Freegle Backup Management System - Authentication</title>
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
        <h1>Freegle Backup Management System</h1>
        <div class="subtitle">Yesterday - Historical Data Access</div>
        <form method="POST" action="/auth/login">
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="text" name="token" placeholder="6-digit code" pattern="[0-9]{6}" required>
            <button type="submit">Authenticate</button>
        </form>
        <div class="info">Use Google Authenticator or similar TOTP app</div>
        {{ERROR}}
    </div>
</body>
</html>
`;

// Middleware to check authentication
function requireAuth(req, res, next) {
    const clientIP = getClientIP(req);

    if (isWhitelisted(clientIP)) {
        return next();
    }

    res.status(401).send(loginPageHTML.replace('{{ERROR}}', ''));
}

// Login page
app.get('/auth/login', (req, res) => {
    res.send(loginPageHTML.replace('{{ERROR}}', ''));
});

// Handle login
app.post('/auth/login', async (req, res) => {
    const { username, token } = req.body;
    const clientIP = getClientIP(req);

    if (!username || !token) {
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Username and token required</div>'));
    }

    const user = users[username];
    if (!user) {
        console.log(`Login attempt for unknown user: ${username} from ${clientIP}`);
        return res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Invalid username or token</div>'));
    }

    const verified = speakeasy.totp.verify({
        secret: user.secret,
        encoding: 'base32',
        token: token,
        window: 2 // Allow 2 time steps drift
    });

    if (verified) {
        await whitelistIP(clientIP, username);
        console.log(`Successful login: ${username} from ${clientIP}`);
        res.redirect('/');
    } else {
        console.log(`Failed login attempt: ${username} from ${clientIP}`);
        res.send(loginPageHTML.replace('{{ERROR}}', '<div class="error">Invalid token</div>'));
    }
});

// Health check
app.get('/health', (req, res) => {
    res.json({ status: 'ok', users: Object.keys(users).length, whitelisted_ips: Object.keys(ipWhitelist).length });
});

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
        created: users[username].created
    }));
    res.json({ users: userList });
});

// Add user
app.post('/admin/users', requireAdminKey, async (req, res) => {
    const { username } = req.body;

    if (!username) {
        return res.status(400).json({ error: 'Username required' });
    }

    if (users[username]) {
        return res.status(409).json({ error: 'User already exists' });
    }

    const secret = speakeasy.generateSecret({
        name: `Yesterday (${username})`,
        issuer: 'Freegle Yesterday'
    });

    users[username] = {
        secret: secret.base32,
        created: new Date().toISOString()
    };

    await saveUsers();

    res.json({
        username,
        secret: secret.base32,
        qr_code_url: secret.otpauth_url,
        message: 'User created. Scan QR code with Google Authenticator'
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

// Proxy to backend (Yesterday API and UI)
app.use(requireAuth, (req, res) => {
    const target = req.path.startsWith('/api/') ? 'http://yesterday-api:8082' : 'http://yesterday-index:80';

    const options = {
        hostname: target.includes('8082') ? 'yesterday-api' : 'yesterday-index',
        port: target.includes('8082') ? 8082 : 80,
        path: req.url,
        method: req.method,
        headers: req.headers
    };

    const proxy = http.request(options, (proxyRes) => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        proxyRes.pipe(res);
    });

    proxy.on('error', (err) => {
        console.error('Proxy error:', err);
        res.status(502).send('Bad Gateway');
    });

    req.pipe(proxy);
});

// Start server
async function start() {
    await loadUsers();
    await loadWhitelist();

    // Clean up whitelist every hour
    setInterval(cleanupWhitelist, 60 * 60 * 1000);

    app.listen(PORT, () => {
        console.log(`2FA Gateway listening on port ${PORT}`);
        console.log(`Admin key: ${ADMIN_KEY}`);
    });
}

start();
