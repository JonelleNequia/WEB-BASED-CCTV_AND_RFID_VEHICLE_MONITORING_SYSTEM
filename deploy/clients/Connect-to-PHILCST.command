#!/bin/sh
# Intel/Apple Silicon client-only helper. No Python, Homebrew, Node or server service.
set -eu
D=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$D/client-common.sh"
[ "$(uname -s)" = Darwin ] || { fail 'Native macOS client required.';exit 1; }
ACTION=connect;ADDRESS=;ROOT_CERT=;FINGERPRINT=;RESOLUTION=
while [ "$#" -gt 0 ]; do
 case "$1" in
  --action) ACTION=$2;shift 2;; --server) ADDRESS=$2;shift 2;; --root) ROOT_CERT=$2;shift 2;;
  --fingerprint) FINGERPRINT=$2;shift 2;; --dns) RESOLUTION=dns;shift;;
  *) fail 'Use --action connect|repair|status|remove --server IP --root root.crt --fingerprint SHA256 [--dns]';exit 1;;
 esac
done
STATE='/Library/Application Support/PHILCST-VMS-Client'
RECEIPT="$STATE/connection"
SHORTCUT='/Users/Shared/Open PHILCST Server.webloc'
for item in "$STATE" "$RECEIPT" "$STATE/root.crt" "$SHORTCUT"; do [ ! -L "$item" ] || { fail 'Enrollment path is a symlink; nothing changed.';exit 1; };done
if [ -d "$STATE" ]; then [ "$(/usr/bin/stat -f %u "$STATE")" = 0 ] || { fail 'Enrollment state is not administrator-owned.';exit 1; };fi
for item in "$STATE" "$RECEIPT" "$STATE/root.crt"; do
 if [ -e "$item" ]; then
  permissions=$(/usr/bin/stat -f %Lp "$item")
  [ "$(/usr/bin/stat -f %u "$item")" = 0 ] && [ "$((0$permissions & 022))" -eq 0 ] || { fail 'Enrollment ownership/permissions are unsafe. Nothing changed.';exit 1; }
 fi
done
old_value() { [ ! -f "$RECEIPT" ] || sed -n "s/^$1=//p" "$RECEIPT"; }
case "$ACTION" in connect|repair|status|remove) ;; *) fail 'Unknown action';exit 1;; esac
if [ "$ACTION" = status ] || [ "$ACTION" = repair ]; then
 [ -f "$RECEIPT" ] || { fail 'No owned enrollment. Connect using the public root and independent fingerprint.';exit 1; }
 [ -n "$ADDRESS" ] || ADDRESS=$(old_value ADDRESS)
 [ -n "$ROOT_CERT" ] || ROOT_CERT="$STATE/root.crt"
 [ -n "$FINGERPRINT" ] || FINGERPRINT=$(old_value FINGERPRINT)
 [ -n "$RESOLUTION" ] || RESOLUTION=$(old_value RESOLUTION)
fi
RESOLUTION=${RESOLUTION:-hosts}
if [ "$ACTION" != status ]; then [ "$(id -u)" = 0 ] || { fail 'Run with sudo: this action changes the managed hosts entry, selected public-root trust and shared Open shortcut.';exit 1; };fi
WORK=$(mktemp -d "${TMPDIR:-/tmp}/philcst-client.XXXXXX")
trap 'rm -rf "$WORK"' EXIT HUP INT TERM
if [ "$ACTION" = remove ]; then
 if [ ! -f "$RECEIPT" ]; then echo 'No owned enrollment to remove.';exit 0;fi
 # Validate ownership before the first write. Expired owned roots can still be removed.
 if [ "$(old_value ROOT_ADDED)" = 1 ]; then
  fingerprint=$(old_value FINGERPRINT)
  validate_root_identity "$STATE/root.crt" "$fingerprint"
 fi
 if [ "$(old_value HOSTS_ADDED)" = 1 ]; then
  hosts_plan '' </etc/hosts >"$WORK/hosts" || { fail 'Managed hosts block was altered; repair manually.';exit 1; }
  cat "$WORK/hosts" >/etc/hosts
 fi
 if [ "$(old_value ROOT_ADDED)" = 1 ]; then
  fingerprint=$(old_value FINGERPRINT)
  /usr/bin/security remove-trusted-cert -d "$STATE/root.crt"
  /usr/bin/security delete-certificate -Z "$fingerprint" /Library/Keychains/System.keychain
 fi
 if [ "$(old_value SHORTCUT_ADDED)" = 1 ] && [ -f "$SHORTCUT" ] && cmp -s "$SHORTCUT" "$STATE/shortcut"; then rm "$SHORTCUT";fi
 rm -f "$RECEIPT" "$STATE/root.crt" "$STATE/shortcut";rmdir "$STATE"
 /usr/bin/dscacheutil -flushcache
 echo 'Removed only owned client enrollment. The server and its records are unchanged.';exit 0
fi
validate_address "$ADDRESS"
validate_root "$ROOT_CERT" "$FINGERPRINT"
FINGERPRINT=$(root_fingerprint "$ROOT_CERT")
echo "Verified public root SHA-256: $FINGERPRINT"
if [ -f "$RECEIPT" ] && [ "$(old_value FINGERPRINT)" != "$FINGERPRINT" ]; then fail 'Different issuer; explicitly remove the earlier enrollment first.';exit 1;fi
if [ "$ACTION" = status ]; then
 probe_https "$ADDRESS" "$ROOT_CERT"
 root_present "$FINGERPRINT" || { fail 'System public root is missing; use Repair.';exit 1; }
 /usr/bin/curl --noproxy '*' --connect-timeout 5 --max-time 12 --fail --silent --show-error "$VMS_URL/up" >/dev/null
 echo "Ready: $VMS_URL";exit 0
fi
if [ "$RESOLUTION" = hosts ]; then
 hosts_plan "$ADDRESS" </etc/hosts >"$WORK/hosts" || { fail 'Unowned/conflicting IPv4 or IPv6 mapping. Nothing changed.';exit 1; }
else
 hosts_plan '' </etc/hosts >"$WORK/hosts"
 addresses=$(/usr/bin/dscacheutil -q host -a name "$VMS_HOST" | awk '/ip_address:|ipv6_address:/{print $2}' | sort -u)
 [ "$addresses" = "$ADDRESS" ] || { fail 'DNS must resolve exclusively to the chosen IPv4 server. Correct A/AAAA records first.';exit 1; }
fi
# Verify hostname, address and selected root before importing trust or changing a mapping.
probe_https "$ADDRESS" "$ROOT_CERT"
if [ -d "$STATE" ] && [ ! -f "$RECEIPT" ] && [ -n "$(ls -A "$STATE")" ]; then fail 'Unrecognized enrollment state directory.';exit 1;fi
cat >"$WORK/shortcut" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict><key>URL</key><string>https://philcst.vms.internal</string></dict></plist>
XML
if [ -f "$SHORTCUT" ] && ! cmp -s "$SHORTCUT" "$WORK/shortcut"; then fail 'The shortcut path belongs to someone else.';exit 1;fi
ROOT_ADDED=$(old_value ROOT_ADDED);ROOT_ADDED=${ROOT_ADDED:-0}
SHORTCUT_ADDED=$(old_value SHORTCUT_ADDED);SHORTCUT_ADDED=${SHORTCUT_ADDED:-0}
[ -f "$SHORTCUT" ] || SHORTCUT_ADDED=1
cp /etc/hosts "$WORK/original-hosts"
cp "$ROOT_CERT" "$WORK/root.crt"
STATE_CREATED=0;[ -d "$STATE" ] || STATE_CREATED=1
for name in connection root.crt shortcut; do [ ! -f "$STATE/$name" ] || cp "$STATE/$name" "$WORK/old-$name";done
[ ! -f "$SHORTCUT" ] || cp "$SHORTCUT" "$WORK/old-shortcut-file"
ADDED_NOW=0;APPLIED=0
rollback() {
 if [ "$APPLIED" = 0 ]; then
  cat "$WORK/original-hosts" >/etc/hosts
  if [ "$ADDED_NOW" = 1 ]; then /usr/bin/security remove-trusted-cert -d "$WORK/root.crt" >/dev/null 2>&1 || :; /usr/bin/security delete-certificate -Z "$FINGERPRINT" /Library/Keychains/System.keychain >/dev/null 2>&1 || :;fi
  if [ -d "$STATE" ]; then
   for name in connection root.crt shortcut; do
    if [ -f "$WORK/old-$name" ]; then cp "$WORK/old-$name" "$STATE/$name";else rm -f "$STATE/$name";fi
   done
   rm -f "$STATE/.connection-new"
   if [ "$STATE_CREATED" = 1 ]; then rmdir "$STATE" 2>/dev/null || :;fi
  fi
  if [ -f "$WORK/old-shortcut-file" ]; then cp "$WORK/old-shortcut-file" "$SHORTCUT";elif [ "$SHORTCUT_ADDED" = 1 ]; then rm -f "$SHORTCUT";fi
 fi
 rm -rf "$WORK"
}
trap rollback EXIT HUP INT TERM
if root_present "$FINGERPRINT"; then
 if ! /usr/bin/curl --noproxy '*' --resolve "$VMS_HOST:443:$ADDRESS" --connect-timeout 5 --max-time 12 --fail --silent "$VMS_URL/up" >/dev/null; then
  [ "$ROOT_ADDED" = 1 ] || { fail 'Root already exists with unowned trust settings. Ask the administrator to resolve them explicitly.';exit 1; }
  /usr/bin/security add-trusted-cert -d -r trustRoot -p ssl -s "$VMS_HOST" -k /Library/Keychains/System.keychain "$WORK/root.crt"
 fi
else
 /usr/bin/security add-trusted-cert -d -r trustRoot -p ssl -s "$VMS_HOST" -k /Library/Keychains/System.keychain "$WORK/root.crt"
 ADDED_NOW=1;ROOT_ADDED=1
fi
cat "$WORK/hosts" >/etc/hosts
mkdir -p "$STATE";chmod 755 "$STATE";chown root:wheel "$STATE"
cp "$WORK/root.crt" "$STATE/root.crt";cp "$WORK/shortcut" "$STATE/shortcut";cp "$WORK/shortcut" "$SHORTCUT"
HOSTS_ADDED=0;[ "$RESOLUTION" != hosts ] || HOSTS_ADDED=1
printf 'ADDRESS=%s\nFINGERPRINT=%s\nROOT_ADDED=%s\nHOSTS_ADDED=%s\nSHORTCUT_ADDED=%s\nRESOLUTION=%s\n' "$ADDRESS" "$FINGERPRINT" "$ROOT_ADDED" "$HOSTS_ADDED" "$SHORTCUT_ADDED" "$RESOLUTION" >"$STATE/.connection-new"
chmod 644 "$STATE/root.crt" "$STATE/shortcut" "$STATE/.connection-new" "$SHORTCUT";chown root:wheel "$STATE/root.crt" "$STATE/shortcut" "$STATE/.connection-new" "$SHORTCUT"
mv "$STATE/.connection-new" "$RECEIPT"
/usr/bin/dscacheutil -flushcache
APPLIED=1
echo "Connected. Open $SHORTCUT ($VMS_URL). See CLIENT-CONNECTION.md for Firefox trust settings."
