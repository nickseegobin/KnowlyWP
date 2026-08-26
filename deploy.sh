#!/bin/bash
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
OUT="$HOME/Desktop/${PLUGIN_NAME}.zip"
cd "$(dirname "$PLUGIN_DIR")"
zip -r "$OUT" "$PLUGIN_NAME" \
  --exclude "${PLUGIN_NAME}/.git/*" \
  --exclude "${PLUGIN_NAME}/.git" \
  --exclude "${PLUGIN_NAME}/deploy.sh" \
  --exclude "${PLUGIN_NAME}/.DS_Store" \
  --exclude "${PLUGIN_NAME}/**/.DS_Store" \
  --exclude "${PLUGIN_NAME}/node_modules/*" -q
echo "✓  $(du -sh "$OUT" | cut -f1)  →  $OUT"
