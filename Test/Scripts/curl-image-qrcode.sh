#!/usr/bin/env bash
# Image / Qrcode 联调 curl（需先启动：php cli.php start Test）
set -euo pipefail
BASE="${BASE_URL:-http://127.0.0.1:9501}"

echo "BASE=$BASE"
curl -sf "$BASE/api/image/create?width=320&height=200" | jq '.data.code, .data.data.mime, .data.data.bytes'
curl -sf "$BASE/api/image/preset?preset=thumb" | jq '.data.code, .data.data.width, .data.data.height'
curl -sf "$BASE/api/image/preset?preset=avatar" | jq '.data.code, .data.data.mime'
curl -sf "$BASE/api/image/watermark" | jq '.data.code, .data.data.watermark_applied'
curl -sf "$BASE/api/qrcode/png?url=https://example.com/test" | jq '.data.code, .data.data.format, .data.data.bytes'
curl -sf "$BASE/api/qrcode/svg?text=hello" | jq '.data.code, (.data.data.svg|length)'
curl -sf "$BASE/api/qrcode/wifi?ssid=Demo&password=secret" | jq '.data.code'
curl -sf "$BASE/api/qrcode/logo?url=https://example.com" | jq '.data.code'
curl -sf "$BASE/api/image/png" -o /tmp/swoolefy-image-demo.png && file /tmp/swoolefy-image-demo.png
curl -sf "$BASE/api/qrcode/png-inline" -o /tmp/swoolefy-qr-demo.png && file /tmp/swoolefy-qr-demo.png
echo "OK"
