#!/bin/bash

# Rocky Linux Backend Development Environment Setup Script
# Run this script with root privileges (sudo)

echo "===================================================="
echo " Rocky Linux - Network Traffic Monitor Dev Setup"
echo "===================================================="

# Check if script is run as root
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (using sudo)."
  exit 1
fi

echo "[1/3] Updating package repositories..."
dnf check-update -y &>/dev/null
# Note: dnf check-update returns 100 if there are updates available, which is normal.

echo "[2/3] Installing Core Build Tools and Libraries..."
# - gcc-c++: C++ compiler
# - make: build automation tool
# - libpcap-devel: packet capture development headers
# - mariadb-devel: MySQL client library development headers (required for mysqlclient linking)
dnf install -y gcc-c++ make libpcap-devel mariadb-devel

if [ $? -eq 0 ]; then
    echo "----------------------------------------------------"
    echo " Core development packages installed successfully!"
else
    echo "----------------------------------------------------"
    echo " Failed to install required packages. Please check internet connection or repository status."
    exit 1
fi

echo "[3/3] Setting up Manticore Search Repository (Optional but recommended)..."
# Registers Manticore Search RPM repository for Rocky Linux
dnf install -y https://repo.manticoresearch.com/manticore-repo.noarch.rpm

echo "----------------------------------------------------"
echo " Backend development setup completed!"
echo " Next Steps:"
echo "   1) Install Manticore Search: sudo dnf install -y manticore manticore-extra"
echo "   2) Build the collector: cd backend && make"
echo "===================================================="
