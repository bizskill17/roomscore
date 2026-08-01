#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_FILE="$ROOT_DIR/hotel-room-inspection-manager.php"
PACKAGE_DIR_NAME="hotel-room-inspection-manager"
BUILD_ROOT="$ROOT_DIR/.build-release"
PACKAGE_DIR="$BUILD_ROOT/$PACKAGE_DIR_NAME"

if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo "Error: plugin file not found: $PLUGIN_FILE" >&2
    exit 1
fi

VERSION="$(grep -E '^\s*\*\s*Version:\s*' "$PLUGIN_FILE" | head -n1 | sed -E 's/^\s*\*\s*Version:\s*//; s/[[:space:]]+$//')"
if [[ -z "$VERSION" ]]; then
    echo "Error: could not read plugin version from header" >&2
    exit 1
fi

ZIP_NAME="roomscore-v${VERSION}.zip"
ZIP_PATH="$ROOT_DIR/$ZIP_NAME"

rm -rf "$BUILD_ROOT"
rm -f "$ZIP_PATH"
mkdir -p "$PACKAGE_DIR"

items=(
    assets
    includes
    templates
    vendor
    hotel-room-inspection-manager.php
    readme.txt
    composer.json
    composer.lock
)

for item in "${items[@]}"; do
    if [[ ! -e "$ROOT_DIR/$item" ]]; then
        echo "Error: required production item missing: $item" >&2
        exit 1
    fi
    cp -a "$ROOT_DIR/$item" "$PACKAGE_DIR/"
done

(
    cd "$BUILD_ROOT"
    zip -rq "$ZIP_PATH" "$PACKAGE_DIR_NAME"
)

rm -rf "$BUILD_ROOT"

echo "Created release ZIP: $ZIP_NAME"
