#!/usr/bin/env bash
set -euo pipefail

# Build a Shopware-compatible plugin ZIP.
# Shopware requires the first archive entry to be the plugin directory
# (e.g. jovepay-shopware/composer.json), not loose files at the zip root.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
OUTPUT_DIR="${1:-$(dirname "$PLUGIN_DIR")}"
OUTPUT_FILE="${OUTPUT_DIR}/${PLUGIN_NAME}.zip"

cd "$(dirname "$PLUGIN_DIR")"

rm -f "$OUTPUT_FILE"

zip -r "$OUTPUT_FILE" "$PLUGIN_NAME" \
  -x "${PLUGIN_NAME}/.git/*" \
  -x "${PLUGIN_NAME}/.git*" \
  -x "${PLUGIN_NAME}/.DS_Store" \
  -x "${PLUGIN_NAME}/**/.DS_Store" \
  -x "${PLUGIN_NAME}/**/node_modules/*" \
  -x "**/__MACOSX/**" \
  -x "${PLUGIN_NAME}/tests/*" \
  -x "${PLUGIN_NAME}/bin/phpunit.sh" \
  -x "${PLUGIN_NAME}/phpunit.xml.dist"

echo "Built: ${OUTPUT_FILE}"
echo "First entries:"
unzip -l "$OUTPUT_FILE" | head -6
