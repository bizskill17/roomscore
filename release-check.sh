#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_FILE="$ROOT_DIR/hotel-room-inspection-manager.php"
AUTOLOAD_FILE="$ROOT_DIR/vendor/autoload.php"
PUC_FILE="$ROOT_DIR/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php"

header_version="$(grep -E '^\s*\*\s*Version:\s*' "$PLUGIN_FILE" | head -n1 | sed -E 's/^\s*\*\s*Version:\s*//; s/[[:space:]]+$//')"
const_version="$(grep -E "define\('HRIM_VERSION'" "$PLUGIN_FILE" | head -n1 | sed -E "s/.*'HRIM_VERSION'\s*,\s*'([^']+)'.*/\1/")"

if [[ -z "$header_version" || -z "$const_version" ]]; then
    echo "FAIL: Unable to extract plugin versions from $PLUGIN_FILE" >&2
    exit 1
fi

if [[ "$header_version" != "$const_version" ]]; then
    echo "FAIL: Version mismatch. Header=$header_version, HRIM_VERSION=$const_version" >&2
    exit 1
fi

echo "PASS: Version check OK ($header_version)"

if [[ ! -f "$AUTOLOAD_FILE" ]]; then
    echo "FAIL: Missing vendor/autoload.php" >&2
    exit 1
fi
echo "PASS: vendor/autoload.php present"

if [[ ! -f "$PUC_FILE" ]]; then
    echo "FAIL: Missing Plugin Update Checker package" >&2
    exit 1
fi
echo "PASS: Plugin Update Checker package present"

ZIP_NAME="roomscore-v${header_version}.zip"
ZIP_PATH="$ROOT_DIR/$ZIP_NAME"

if [[ ! -f "$ZIP_PATH" ]]; then
    echo "FAIL: Build ZIP not found: $ZIP_NAME" >&2
    exit 1
fi
echo "PASS: Build ZIP exists ($ZIP_NAME)"

zip_entries="$(unzip -Z1 "$ZIP_PATH")"

if ! grep -q '^hotel-room-inspection-manager/' <<< "$zip_entries"; then
    echo "FAIL: ZIP root folder is not hotel-room-inspection-manager/" >&2
    exit 1
fi

if grep -qv '^hotel-room-inspection-manager/' <<< "$zip_entries"; then
    echo "FAIL: ZIP contains files outside hotel-room-inspection-manager/ root" >&2
    exit 1
fi
echo "PASS: ZIP root folder structure is valid"

if grep -E -q '(^|/)\.git(/|$)' <<< "$zip_entries"; then
    echo "FAIL: ZIP contains .git directory" >&2
    exit 1
fi
echo "PASS: ZIP does not include .git directory"

if git -C "$ROOT_DIR" grep -nE 'github_pat_' -- . >/dev/null; then
    echo "FAIL: Found github_pat_ pattern in tracked files" >&2
    git -C "$ROOT_DIR" grep -nE 'github_pat_' -- . >&2
    exit 1
fi
echo "PASS: No github_pat_ values found in tracked files"

echo "All release checks passed."
