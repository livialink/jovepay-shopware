#!/usr/bin/env bash
set -euo pipefail

# Build a Shopware Store-compatible plugin ZIP.
# First archive entry must be the plugin directory containing composer.json.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
OUTPUT_DIR="${1:-$(dirname "$PLUGIN_DIR")}"
OUTPUT_FILE="${OUTPUT_DIR}/${PLUGIN_NAME}.zip"

cd "$(dirname "$PLUGIN_DIR")"
rm -f "$OUTPUT_FILE"

# Prefer shopware-cli when available (applies .sw-zip-blacklist).
if command -v shopware-cli >/dev/null 2>&1; then
  shopware-cli extension zip "$PLUGIN_DIR" --output-directory "$OUTPUT_DIR"
  echo "Built with shopware-cli: ${OUTPUT_FILE}"
else
  zip -r "$OUTPUT_FILE" "$PLUGIN_NAME" \
    -x "${PLUGIN_NAME}/.git/*" \
    -x "${PLUGIN_NAME}/.git*" \
    -x "${PLUGIN_NAME}/.DS_Store" \
    -x "${PLUGIN_NAME}/**/.DS_Store" \
    -x "${PLUGIN_NAME}/.idea/*" \
    -x "${PLUGIN_NAME}/.vscode/*" \
    -x "${PLUGIN_NAME}/.sw-zip-blacklist" \
    -x "${PLUGIN_NAME}/.gitignore" \
    -x "${PLUGIN_NAME}/.gitattributes" \
    -x "${PLUGIN_NAME}/**/node_modules/*" \
    -x "**/__MACOSX/**" \
    -x "${PLUGIN_NAME}/tests/*" \
    -x "${PLUGIN_NAME}/tests/**" \
    -x "${PLUGIN_NAME}/bin/*" \
    -x "${PLUGIN_NAME}/phpunit.xml.dist" \
    -x "${PLUGIN_NAME}/composer.lock" \
    -x "${PLUGIN_NAME}/README.md" \
    -x "${PLUGIN_NAME}/**/*.zip"
fi

echo "Built: ${OUTPUT_FILE}"
echo "First entries:"
unzip -l "$OUTPUT_FILE" | head -8

# Guard against flat zips (composer.json at archive root).
if unzip -l "$OUTPUT_FILE" | awk 'NR>3 {print $4}' | grep -q '^composer.json$'; then
  echo "ERROR: ZIP has composer.json at root. Shopware requires a plugin folder wrapper." >&2
  exit 1
fi
