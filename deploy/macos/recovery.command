#!/bin/bash
# Installed trusted D5B control. Incoming backups never supply executable tools.
set -euo pipefail
ROOT="${PHILCST_VMS_ROOT:-/Library/Application Support/PHILCST-VMS}"
RELEASE="$ROOT/current"
if [[ ! -f "$RELEASE/BUILD-INFO.json" || ! -f "$ROOT/shared/lifecycle/identity.json" ]]; then
  echo 'Use an initialized installed D5B package. This helper cannot repair a development/legacy install.' >&2
  exit 1
fi
exec sudo "$RELEASE/runtime/python/bin/python3" -B "$RELEASE/app/deploy/lifecycle/recovery.py" "${1:-status}" --root "$ROOT" "${@:2}"
