#!/usr/bin/env bash
# Start the portfolio tracker on http://localhost:8000
set -euo pipefail
cd "$(dirname "$0")"
PORT="${1:-8000}"
PHP="${PHP_BIN:-php}"
echo "Starting ${PHP} on http://localhost:${PORT}"
# The upload caps are for 設定頁的上傳還原: php.ini defaults to 2 MB, which a year of daily quotes outgrows.
exec "${PHP}" \
  -d upload_max_filesize=64M \
  -d post_max_size=64M \
  -S "localhost:${PORT}" -t public
