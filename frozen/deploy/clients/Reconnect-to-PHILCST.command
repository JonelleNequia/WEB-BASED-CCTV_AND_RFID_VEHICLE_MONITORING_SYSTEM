#!/bin/sh
# Explicit client handoff; built-in tools, no runtime/DB/collector installation.
set -eu
D=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
[ "$(uname -s)" = Darwin ] && [ "$(id -u)" = 0 ] || { echo 'Native Mac administrator required.' >&2; exit 1; }
[ "$#" -ge 5 ] || { echo 'Usage: sudo ./Reconnect-to-PHILCST.command OLD_IP NEW_IP PUBLIC_ROOT SHA256 --old-server-isolated [--dns]' >&2;exit 1; }
OLD=$1;NEW=$2;ROOT=$3;FINGERPRINT=$4;shift 4
[ "$1" = --old-server-isolated ] || { echo 'Verify physical/network isolation first; timeout is not fencing.' >&2;exit 1; };shift
RECEIPT='/Library/Application Support/PHILCST-VMS-Client/connection'
[ -f "$RECEIPT" ] && [ ! -L "$RECEIPT" ] || { echo 'No safe owned enrollment. Use independently verified Connect.' >&2;exit 1; }
[ "$(/usr/bin/stat -f %u "$RECEIPT")" = 0 ] || { echo 'Unowned enrollment receipt.' >&2;exit 1; }
[ "$(sed -n 's/^ADDRESS=//p' "$RECEIPT")" = "$OLD" ] || { echo 'Stale/conflicting old mapping; inspect before handoff.' >&2;exit 1; }
[ "$(sed -n 's/^FINGERPRINT=//p' "$RECEIPT")" = "$(printf '%s' "$FINGERPRINT" | tr 'A-F' 'a-f')" ] || { echo 'Issuer rotation needs separate verified Remove and re-enrollment.' >&2;exit 1; }
exec /bin/sh "$D/Connect-to-PHILCST.command" --action connect --server "$NEW" --root "$ROOT" --fingerprint "$FINGERPRINT" "$@"
