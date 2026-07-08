#!/bin/bash

# ==============================================================================
# iOS (Local Terminal) -> Remote Linux Server Deployment & Build Script
# This script syncs your local workspace to a remote Linux server and compiles the backend.
# Run this from Blink Shell, Termius, iSH, or any iOS terminal client.
# ==============================================================================

# --- [CONFIGURATION] ---
# Fill in your remote Linux server details
REMOTE_USER="root"
REMOTE_HOST="your-remote-linux-ip"
REMOTE_PORT="22"
REMOTE_DIR="/opt/MiniMAGUX" # Destination directory on remote Linux server

# Prevent execution without configuration
if [ "$REMOTE_HOST" == "your-remote-linux-ip" ]; then
    echo "ERROR: Please edit this script and configure your REMOTE_HOST / REMOTE_USER first."
    exit 1
fi

echo "===================================================="
echo " 🚀 Deploying MiniMAGUX to Remote Linux Server"
echo "===================================================="

# 1. Sync files to the remote server
echo "[1/3] Syncing files to remote server..."
# Using rsync if available, otherwise fallback to scp
if command -v rsync &> /dev/null; then
    rsync -avz -e "ssh -p $REMOTE_PORT" --exclude='.git' --exclude='backend/bin/collector' --exclude='backend/bin/csv_logs' --exclude='backend/bin/collector.pid' --exclude='backend/csv_logs' --exclude='backend/collector.pid' ./ $REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/
else
    echo "rsync not found. Falling back to scp (this may take longer)..."
    ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "mkdir -p $REMOTE_DIR"
    scp -P $REMOTE_PORT -r ./backend ./frontend $REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/
fi

if [ $? -ne 0 ]; then
    echo "❌ ERROR: File synchronization failed."
    exit 1
fi

# 2. Run remote compilation
echo "[2/3] Triggering C++ compilation on remote server..."
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "
    cd $REMOTE_DIR/backend && \
    chmod +x setup-dev.backend.sh && \
    ./setup-dev.backend.sh && \
    make clean && \
    make
"

if [ $? -eq 0 ]; then
    echo "===================================================="
    echo " 🎉 Build completed successfully on remote server!"
    echo " To run remote tests, execute:"
    echo "   ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST 'bash $REMOTE_DIR/remote-test.sh'"
    echo "===================================================="
else
    echo "❌ ERROR: Compilation failed on remote server."
    exit 1
fi
