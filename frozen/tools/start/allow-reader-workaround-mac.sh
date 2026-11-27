#!/bin/sh
# PHILCST Vehicle Monitoring - TEMPORARY WORKAROUND, development Macs only.
#
# Lets the device service add (and remove) an extra address on a wired
# network card without asking for a password, so a reader that is still on
# another subnet works without typing `sudo ifconfig ... alias` after every
# restart. Allowed: `/sbin/ifconfig en<N> alias|-alias ...` only.
#
# The real fix: Settings > Gates > "Move reader to this network" (or
# NetModuleConfig). Then this is not needed; remove it with --remove.
#
#   sudo tools/start/allow-reader-workaround-mac.sh           add the permission
#   sudo tools/start/allow-reader-workaround-mac.sh --remove  remove it

FILE=/etc/sudoers.d/philcst-reader-workaround

[ "$(id -u)" = 0 ] || { echo "Run it with sudo: sudo $0 $*"; exit 1; }

if [ "$1" = "--remove" ]; then
    rm -f "$FILE" && echo "Permission removed."
    exit 0
fi

USER_NAME="${SUDO_USER:-}"
[ -n "$USER_NAME" ] && [ "$USER_NAME" != "root" ] || { echo "Run it with sudo from the account that runs the system."; exit 1; }

cat > "$FILE.tmp" <<EOF
# PHILCST vehicle monitoring: temporary extra address for an RFID reader on another subnet (development only).
$USER_NAME ALL=(root) NOPASSWD: /sbin/ifconfig en[0-9]* alias *, /sbin/ifconfig en[0-9]* -alias *
EOF

if visudo -cf "$FILE.tmp" > /dev/null; then
    chmod 0440 "$FILE.tmp" && mv "$FILE.tmp" "$FILE"
    echo "Done. The system adds the extra address by itself within a minute (Settings > Gates shows it)."
else
    rm -f "$FILE.tmp"
    echo "The permission file was not valid; nothing was changed."
    exit 1
fi
