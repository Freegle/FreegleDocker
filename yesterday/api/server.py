#!/usr/bin/env python3
"""
Yesterday Backup Management API

Provides endpoints for:
- Listing available backups from GCS
- Starting backup restoration with progress tracking
- Monitoring restoration progress
- Managing running backup containers
"""

from flask import Flask, jsonify, request
import subprocess
import os
import re
import json
import threading
from datetime import datetime
from pathlib import Path

app = Flask(__name__)

# Global state for tracking restoration progress
restoration_jobs = {}
"""
Structure:
{
    "20251031": {
        "status": "downloading|extracting|preparing|importing|completed|failed",
        "progress": 0-100,
        "message": "Current status message",
        "started": timestamp,
        "completed": timestamp or None,
        "error": error message or None
    }
}
"""

def parse_backup_date(filename):
    """Extract date from backup filename: iznik-2025-10-31-04-00.xbstream -> 20251031"""
    match = re.search(r'iznik-(\d{4})-(\d{2})-(\d{2})', filename)
    if match:
        return f"{match.group(1)}{match.group(2)}{match.group(3)}"
    return None

def format_size(bytes_size):
    """Convert bytes to human readable format"""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if bytes_size < 1024.0:
            return f"{bytes_size:.1f} {unit}"
        bytes_size /= 1024.0
    return f"{bytes_size:.1f} PB"

@app.route('/api/backups', methods=['GET'])
def list_backups():
    """List all available backups from GCS bucket"""
    try:
        bucket = os.getenv('BACKUP_BUCKET', 'gs://freegle_backup_uk')

        # Get list of backups with size and date
        result = subprocess.run(
            ['gsutil', 'ls', '-l', f'{bucket}/iznik-*.xbstream'],
            capture_output=True,
            text=True,
            check=True
        )

        backups = []
        for line in result.stdout.strip().split('\n'):
            if 'TOTAL:' in line or not line.strip():
                continue

            parts = line.split()
            if len(parts) >= 3:
                size = int(parts[0])
                date_str = parts[1]
                url = parts[2]
                filename = os.path.basename(url)

                backup_date = parse_backup_date(filename)
                if backup_date:
                    backups.append({
                        'date': backup_date,
                        'filename': filename,
                        'url': url,
                        'size': size,
                        'size_human': format_size(size),
                        'timestamp': date_str,
                        'loaded': is_backup_loaded(backup_date)
                    })

        # Sort by date descending (most recent first)
        backups.sort(key=lambda x: x['date'], reverse=True)

        return jsonify({
            'backups': backups,
            'total': len(backups)
        })

    except subprocess.CalledProcessError as e:
        return jsonify({'error': f'Failed to list backups: {e.stderr}'}), 500
    except Exception as e:
        return jsonify({'error': str(e)}), 500

def is_backup_loaded(backup_date):
    """Check if a backup is currently loaded (containers running)"""
    try:
        result = subprocess.run(
            ['docker', 'ps', '--filter', f'name=yesterday-{backup_date}', '--format', '{{.Names}}'],
            capture_output=True,
            text=True
        )
        return bool(result.stdout.strip())
    except:
        return False

@app.route('/api/backups/<backup_date>/load', methods=['POST'])
def load_backup(backup_date):
    """Start loading a backup (runs restoration in background)"""

    # Check if already loading
    if backup_date in restoration_jobs:
        job = restoration_jobs[backup_date]
        if job['status'] not in ['completed', 'failed']:
            return jsonify({
                'error': 'Backup is already being loaded',
                'status': job['status'],
                'progress': job['progress']
            }), 409

    # Check if already loaded
    if is_backup_loaded(backup_date):
        return jsonify({
            'error': 'Backup is already loaded',
            'status': 'running'
        }), 409

    # Initialize job tracking
    restoration_jobs[backup_date] = {
        'status': 'starting',
        'progress': 0,
        'message': 'Initializing restoration...',
        'started': datetime.utcnow().isoformat(),
        'completed': None,
        'error': None
    }

    # Start restoration in background thread
    thread = threading.Thread(
        target=run_restoration,
        args=(backup_date,)
    )
    thread.daemon = True
    thread.start()

    return jsonify({
        'message': f'Started loading backup {backup_date}',
        'status': 'starting'
    })

def run_restoration(backup_date):
    """Run the restoration script with progress tracking"""
    try:
        script_path = '/var/www/FreegleDocker/yesterday/scripts/restore-backup.sh'

        # Run restoration script
        process = subprocess.Popen(
            [script_path, backup_date],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1
        )

        # Monitor output for progress updates
        for line in iter(process.stdout.readline, ''):
            line = line.strip()

            # Update progress based on log messages
            if 'Downloading backup' in line:
                restoration_jobs[backup_date].update({
                    'status': 'downloading',
                    'progress': 10,
                    'message': 'Downloading backup from GCS...'
                })
            elif 'Extracting xbstream' in line:
                restoration_jobs[backup_date].update({
                    'status': 'extracting',
                    'progress': 30,
                    'message': 'Extracting backup files...'
                })
            elif 'Preparing backup' in line:
                restoration_jobs[backup_date].update({
                    'status': 'preparing',
                    'progress': 50,
                    'message': 'Preparing backup (applying logs)...'
                })
            elif 'Copying restored data' in line:
                restoration_jobs[backup_date].update({
                    'status': 'importing',
                    'progress': 70,
                    'message': 'Importing database...'
                })
            elif 'Starting database with restored data' in line:
                restoration_jobs[backup_date].update({
                    'status': 'starting_services',
                    'progress': 90,
                    'message': 'Starting database container...'
                })

        process.wait()

        if process.returncode == 0:
            restoration_jobs[backup_date].update({
                'status': 'completed',
                'progress': 100,
                'message': 'Restoration completed successfully!',
                'completed': datetime.utcnow().isoformat()
            })
        else:
            restoration_jobs[backup_date].update({
                'status': 'failed',
                'message': 'Restoration failed',
                'error': 'Script exited with non-zero status',
                'completed': datetime.utcnow().isoformat()
            })

    except Exception as e:
        restoration_jobs[backup_date].update({
            'status': 'failed',
            'message': 'Restoration failed with exception',
            'error': str(e),
            'completed': datetime.utcnow().isoformat()
        })

@app.route('/api/backups/<backup_date>/progress', methods=['GET'])
def get_progress(backup_date):
    """Get restoration progress for a backup"""
    if backup_date not in restoration_jobs:
        return jsonify({'error': 'No restoration job found for this backup'}), 404

    return jsonify(restoration_jobs[backup_date])

@app.route('/api/backups/<backup_date>/unload', methods=['POST'])
def unload_backup(backup_date):
    """Stop and remove containers for a backup"""
    try:
        # Stop containers
        subprocess.run(
            ['docker', 'compose', '-f', '/var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml',
             'stop', f'yesterday-{backup_date}-db', f'yesterday-{backup_date}-mailhog'],
            check=True
        )

        return jsonify({
            'message': f'Unloaded backup {backup_date}',
            'status': 'stopped'
        })

    except subprocess.CalledProcessError as e:
        return jsonify({'error': f'Failed to unload backup: {str(e)}'}), 500

@app.route('/api/backups/loaded', methods=['GET'])
def list_loaded_backups():
    """List all currently loaded backups"""
    try:
        result = subprocess.run(
            ['docker', 'ps', '--filter', 'name=yesterday-', '--format', '{{.Names}}'],
            capture_output=True,
            text=True
        )

        loaded_dates = set()
        for name in result.stdout.strip().split('\n'):
            if name:
                # Extract date from container name: yesterday-20251031-db -> 20251031
                match = re.search(r'yesterday-(\d{8})', name)
                if match:
                    loaded_dates.add(match.group(1))

        return jsonify({
            'loaded': sorted(list(loaded_dates), reverse=True),
            'total': len(loaded_dates)
        })

    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/api/current-backup', methods=['GET'])
def get_current_backup():
    """Get information about the currently loaded backup"""
    state_file = '/var/www/FreegleDocker/yesterday/data/current-backup.json'

    try:
        if os.path.exists(state_file):
            with open(state_file, 'r') as f:
                data = json.load(f)
            return jsonify(data)
        else:
            return jsonify({'date': None, 'message': 'No backup currently loaded'})
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return jsonify({'status': 'healthy'})

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8082, debug=True)
