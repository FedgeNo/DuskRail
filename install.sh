#!/usr/bin/env bash
#
# DuskRail install script.
# Mirrors every setup step taken on the dev machine so the project can be
# stood up from scratch on a fresh box. Keep this in sync as the project grows.
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

echo "==> DuskRail install starting in $PROJECT_DIR"

# --- Directory structure -----------------------------------------------
mkdir -p src/classes
mkdir -p config

# --- Local config --------------------------------------------------------
if [ ! -f config/config.php ]; then
    cp config/config.example.php config/config.php
    echo "==> Created config/config.php from example — edit it with real credentials."
else
    echo "==> config/config.php already exists, leaving it alone."
fi

echo "==> DuskRail install finished."
