#!/bin/bash
set -euo pipefail
if [ "$(id -u)" != 0 ]; then exec sudo /bin/bash "$0" "$@"; fi
ROOT="${PHILCST_VMS_ROOT:-/Library/Application Support/PHILCST-VMS}"
ID=$(/usr/bin/plutil -extract id raw -o - "$ROOT/shared/update-controller.json")
[[ "$ID" =~ ^[a-f0-9-]{36}$ ]] || { echo 'Invalid protected controller receipt.' >&2; exit 1; }
CAPSULE="$ROOT/shared/update-controller/$ID"
exec "$CAPSULE/runtime/python/bin/python3" -I -B "$CAPSULE/app/deploy/updates/cli.py" "${1:-status}" --root "$ROOT" "${@:2}"
