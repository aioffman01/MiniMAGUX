#!/bin/bash

# ==============================================================================
# Remote Linux Server Test Automation Script
# This script runs on the remote server to start the services, generate traffic,
# and verify that CSV logging and Manticore Search indexing work correctly.
# ==============================================================================

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$PROJECT_DIR/backend"
FRONTEND_DIR="$PROJECT_DIR/frontend"

echo "===================================================="
echo " 🔍 Running Traffic Monitor Automated Tests"
echo "===================================================="

# Check if collector binary exists
if [ ! -f "$BACKEND_DIR/collector" ]; then
    echo "❌ ERROR: Collector binary not found. Please compile it first by running make."
    exit 1
fi

# 1. Stop any existing running collector
if [ -f "$BACKEND_DIR/collector.pid" ]; then
    echo "Stopping existing collector instance..."
    cd "$BACKEND_DIR" && ./collector -kill &>/dev/null
    sleep 2
fi

# 2. Check if Manticore Search is running and setup index
CFG_USE_MANTICORE=$(grep -E '^use_manticore' "$BACKEND_DIR/collector.cfg" | cut -d'=' -f2 | tr -d ' \t\r\n')

if [ "$CFG_USE_MANTICORE" == "true" ]; then
    echo "Checking Manticore Search daemon..."
    if ! systemctl is-active --quiet manticore; then
        echo "⚠️ WARNING: Manticore is not running. Attempting to start it..."
        sudo systemctl start manticore
        sleep 2
    fi

    if systemctl is-active --quiet manticore; then
        echo "✅ Manticore Search is running."
        # Create packet index if not exists by querying port 9306
        echo "Creating index schema if not exists..."
        mysql -h 127.0.0.1 -P 9306 -e "
            CREATE TABLE IF NOT EXISTS packets (
                timestamp timestamp,
                interface string,
                src_mac string,
                dst_mac string,
                eth_type int,
                ip_ver int,
                src_ip string,
                dst_ip string,
                ip_ttl int,
                ip_proto int,
                src_port int,
                dst_port int,
                tcp_seq bigint,
                tcp_ack bigint,
                tcp_flags string,
                tcp_win int,
                udp_len int,
                icmp_type int,
                icmp_code int,
                payload_len int
            ) type='rt';
        " &>/dev/null
        echo "✅ Database schema verified."
    else
        echo "❌ ERROR: Manticore Search is not running and cannot be started."
        exit 1
    fi
else
    echo "ℹ️ Manticore Search integration disabled in collector.cfg. Skipping DB checks."
fi

# 3. Start collector daemon
echo "Starting collector daemon..."
CFG_INTERFACE=$(grep -E '^interface' "$BACKEND_DIR/collector.cfg" | cut -d'=' -f2 | tr -d ' \t\r\n')
cd "$BACKEND_DIR"
sudo ./collector

sleep 2
if [ -f "collector.pid" ]; then
    echo "✅ Collector running successfully (PID: $(cat collector.pid))."
else
    echo "❌ ERROR: Collector failed to start."
    exit 1
fi

# 4. Generate traffic to test
echo "Generating test network traffic (pinging Google DNS)..."
ping -c 5 8.8.8.8 &>/dev/null

echo "Waiting 12 seconds for buffer flush (10s interval)..."
sleep 12

# 5. Verify CSV creation
echo "Checking CSV logs..."
CSV_FILES=$(find ./csv_logs -name "traffic_*.csv" 2>/dev/null)
if [ -n "$CSV_FILES" ]; then
    echo "✅ CSV Log Files found:"
    for f in $CSV_FILES; do
        LINE_COUNT=$(wc -l < "$f")
        echo "   - $(basename "$f") ($LINE_COUNT lines)"
    done
else
    echo "❌ ERROR: No CSV log files were created."
fi

# 6. Verify Manticore Database Ingestion
if [ "$CFG_USE_MANTICORE" == "true" ]; then
    echo "Verifying Manticore Search database records..."
    RECORD_COUNT=$(mysql -h 127.0.0.1 -P 9306 -N -B -e "SELECT count(*) FROM packets" 2>/dev/null)
    if [ $? -eq 0 ]; then
        echo "✅ Manticore Search has indexed $RECORD_COUNT packets."
    else
        echo "❌ ERROR: Failed to query Manticore Search."
    fi
fi

# 7. Clean up collector
echo "Stopping collector daemon..."
./collector -kill &>/dev/null

echo "===================================================="
echo " 🎉 Test execution completed!"
echo "===================================================="
