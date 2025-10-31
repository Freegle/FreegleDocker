#!/usr/bin/env node
/**
 * Yesterday Backup Management API (Node.js)
 *
 * Provides endpoints for:
 * - Listing available backups from GCS
 * - Starting backup restoration with progress tracking
 * - Monitoring restoration progress
 */

const express = require('express');
const { exec, spawn } = require('child_process');
const fs = require('fs').promises;
const path = require('path');
const { promisify } = require('util');

const execAsync = promisify(exec);

const app = express();
const PORT = process.env.PORT || 8082;

// Global state for tracking restoration progress
const restorationJobs = {};
/*
Structure:
{
  "20251031": {
    status: "downloading|extracting|preparing|importing|completed|failed",
    progress: 0-100,
    message: "Current status message",
    started: timestamp,
    completed: timestamp or null,
    error: error message or null
  }
}
*/

app.use(express.json());

// Helper: Parse backup date from filename
function parseBackupDate(filename) {
  const match = filename.match(/iznik-(\d{4})-(\d{2})-(\d{2})/);
  if (match) {
    return `${match[1]}${match[2]}${match[3]}`;
  }
  return null;
}

// Helper: Format bytes to human readable
function formatSize(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let size = bytes;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }

  return `${size.toFixed(1)} ${units[unitIndex]}`;
}

// Helper: Check if backup is currently loaded
async function isBackupLoaded(backupDate) {
  try {
    const { stdout } = await execAsync(`docker ps --filter "name=freegle-db" --format "{{.Names}}"`);
    // For single-db model, we check the current-backup.json file instead
    const stateFile = '/var/www/FreegleDocker/yesterday/data/current-backup.json';
    try {
      const data = await fs.readFile(stateFile, 'utf8');
      const state = JSON.parse(data);
      return state.date === backupDate;
    } catch {
      return false;
    }
  } catch {
    return false;
  }
}

// GET /api/backups - List all available backups
app.get('/api/backups', async (req, res) => {
  try {
    const bucket = process.env.BACKUP_BUCKET || 'gs://freegle_backup_uk';

    // Get list of backups with size and date
    const { stdout } = await execAsync(`gsutil ls -l "${bucket}/iznik-*.xbstream"`);

    const backups = [];
    const lines = stdout.trim().split('\n');

    for (const line of lines) {
      if (line.includes('TOTAL:') || !line.trim()) continue;

      const parts = line.trim().split(/\s+/);
      if (parts.length >= 3) {
        const size = parseInt(parts[0]);
        const dateStr = parts[1];
        const url = parts[2];
        const filename = path.basename(url);

        const backupDate = parseBackupDate(filename);
        if (backupDate) {
          backups.push({
            date: backupDate,
            filename: filename,
            url: url,
            size: size,
            size_human: formatSize(size),
            timestamp: dateStr,
            loaded: await isBackupLoaded(backupDate)
          });
        }
      }
    }

    // Sort by date descending (most recent first)
    backups.sort((a, b) => b.date.localeCompare(a.date));

    res.json({
      backups: backups,
      total: backups.length
    });

  } catch (error) {
    console.error('Error listing backups:', error);
    res.status(500).json({ error: `Failed to list backups: ${error.message}` });
  }
});

// POST /api/backups/:backupDate/load - Start loading a backup
app.post('/api/backups/:backupDate/load', async (req, res) => {
  const { backupDate } = req.params;

  // Check if already loading
  if (restorationJobs[backupDate]) {
    const job = restorationJobs[backupDate];
    if (job.status !== 'completed' && job.status !== 'failed') {
      return res.status(409).json({
        error: 'Backup is already being loaded',
        status: job.status,
        progress: job.progress
      });
    }
  }

  // Check if already loaded
  if (await isBackupLoaded(backupDate)) {
    return res.status(409).json({
      error: 'Backup is already loaded',
      status: 'running'
    });
  }

  // Initialize job tracking
  restorationJobs[backupDate] = {
    status: 'starting',
    progress: 0,
    message: 'Initializing restoration...',
    started: new Date().toISOString(),
    completed: null,
    error: null
  };

  // Start restoration in background
  runRestoration(backupDate);

  res.json({
    message: `Started loading backup ${backupDate}`,
    status: 'starting'
  });
});

// Run restoration script with progress tracking
function runRestoration(backupDate) {
  const scriptPath = '/var/www/FreegleDocker/yesterday/scripts/restore-backup-with-progress.sh';

  const process = spawn(scriptPath, [backupDate]);

  // Monitor output for progress updates
  process.stdout.on('data', (data) => {
    const output = data.toString();
    console.log(output);

    // Update progress based on log messages
    if (output.includes('Downloading backup')) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'downloading',
        progress: 10,
        message: 'Downloading backup from GCS...'
      };
    } else if (output.includes('Extracting xbstream')) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'extracting',
        progress: 30,
        message: 'Extracting backup files...'
      };
    } else if (output.includes('Preparing backup')) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'preparing',
        progress: 50,
        message: 'Preparing backup (applying logs)...'
      };
    } else if (output.includes('Copying restored data')) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'importing',
        progress: 70,
        message: 'Importing database...'
      };
    } else if (output.includes('Starting all Docker containers')) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'starting_services',
        progress: 90,
        message: 'Starting all containers...'
      };
    }
  });

  process.stderr.on('data', (data) => {
    console.error(data.toString());
  });

  process.on('close', (code) => {
    if (code === 0) {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'completed',
        progress: 100,
        message: 'Restoration completed successfully!',
        completed: new Date().toISOString()
      };
    } else {
      restorationJobs[backupDate] = {
        ...restorationJobs[backupDate],
        status: 'failed',
        message: 'Restoration failed',
        error: `Script exited with code ${code}`,
        completed: new Date().toISOString()
      };
    }
  });
}

// GET /api/backups/:backupDate/progress - Get restoration progress
app.get('/api/backups/:backupDate/progress', (req, res) => {
  const { backupDate } = req.params;

  if (!restorationJobs[backupDate]) {
    return res.status(404).json({
      error: 'No restoration job found for this backup'
    });
  }

  res.json(restorationJobs[backupDate]);
});

// GET /api/current-backup - Get currently loaded backup info
app.get('/api/current-backup', async (req, res) => {
  const stateFile = '/var/www/FreegleDocker/yesterday/data/current-backup.json';

  try {
    const data = await fs.readFile(stateFile, 'utf8');
    const state = JSON.parse(data);
    res.json(state);
  } catch (error) {
    if (error.code === 'ENOENT') {
      res.json({
        date: null,
        message: 'No backup currently loaded'
      });
    } else {
      res.status(500).json({ error: error.message });
    }
  }
});

// GET /health - Health check
app.get('/health', (req, res) => {
  res.json({ status: 'healthy' });
});

// Start server
app.listen(PORT, '0.0.0.0', () => {
  console.log(`Yesterday Backup Management API running on port ${PORT}`);
  console.log(`Endpoints:`);
  console.log(`  GET  /api/backups - List available backups`);
  console.log(`  POST /api/backups/:date/load - Load a backup`);
  console.log(`  GET  /api/backups/:date/progress - Check progress`);
  console.log(`  GET  /api/current-backup - Get current backup info`);
  console.log(`  GET  /health - Health check`);
});
