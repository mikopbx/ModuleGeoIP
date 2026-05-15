#!/usr/bin/env bash
# Refresh the bundled offline DB-IP Lite database in db/dbip-country-lite.csv.gz.
#
# Usage:
#   scripts/update-offline-db.sh            # current month, fall back to previous
#   scripts/update-offline-db.sh 2026-04    # explicit month
#
# Designed to run locally (before commit/release) and in CI.
# Exit codes: 0 ok / updated, 1 download or validation failure.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST_DIR="$REPO_ROOT/db"
DEST_FILE="$DEST_DIR/dbip-country-lite.csv.gz"
URL_TEMPLATE="https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz"

# Min/max sanity bounds (DB-IP Lite is ~4 MB compressed; cap at 50 MB).
MIN_SIZE=$((1 * 1024 * 1024))
MAX_SIZE=$((50 * 1024 * 1024))

mkdir -p "$DEST_DIR"

log() { printf '[update-offline-db] %s\n' "$*" >&2; }

try_download() {
  local month="$1"
  local url="${URL_TEMPLATE//%s/$month}"
  local tmp
  tmp=$(mktemp "${DEST_FILE}.XXXXXX")
  # Guarantee tmp cleanup on any exit path (including unexpected failures)
  trap 'rm -f "$tmp"' RETURN

  log "Trying $url"
  if ! curl -fSL --connect-timeout 15 --max-time 180 -o "$tmp" "$url"; then
    return 1
  fi

  local size
  size=$(wc -c < "$tmp" | tr -d ' ')
  if [ "$size" -lt "$MIN_SIZE" ] || [ "$size" -gt "$MAX_SIZE" ]; then
    log "Downloaded file size $size out of bounds, rejecting"
    return 1
  fi

  if ! gzip -t "$tmp"; then
    log "Gzip integrity check failed"
    return 1
  fi

  # Quick CSV sanity: first non-empty row must be start_ip,end_ip,CC.
  # Avoid SIGPIPE killing gzip under pipefail by reading a small fixed chunk.
  local first_line
  first_line=$(gzip -dc "$tmp" 2>/dev/null | awk 'NR==1{print;exit}')
  if ! printf '%s\n' "$first_line" | grep -Eq '^[0-9a-fA-F.:]+,[0-9a-fA-F.:]+,[A-Z]{2}$'; then
    log "CSV format check failed (first line: '$first_line')"
    return 1
  fi

  # Hand-off: disable RETURN trap so the mv doesn't get cleaned up
  trap - RETURN
  mv -f "$tmp" "$DEST_FILE"
  chmod 644 "$DEST_FILE"
  log "Updated $DEST_FILE ($size bytes, source month $month)"
  return 0
}

MONTH="${1:-$(date -u +%Y-%m)}"
PREV_MONTH="$(date -u -v -1m +%Y-%m 2>/dev/null || date -u -d '1 month ago' +%Y-%m)"

if try_download "$MONTH"; then
  exit 0
fi

if [ "$MONTH" != "$PREV_MONTH" ]; then
  log "Falling back to previous month: $PREV_MONTH"
  if try_download "$PREV_MONTH"; then
    exit 0
  fi
fi

log "Failed to refresh offline DB-IP Lite database"
exit 1
