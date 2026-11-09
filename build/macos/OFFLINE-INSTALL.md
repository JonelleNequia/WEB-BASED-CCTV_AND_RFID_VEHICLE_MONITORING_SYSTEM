# PHILCST VMS Mac offline candidate — named HTTPS, local by default

**Current D5A source:** fresh installation initializes protected delivery/maintenance
state. Subsequent reinstall and the old Stop shortcut refuse until the certified
lifecycle path is available. Use MAINTENANCE.md Enter/Verify/Resume for an intentional
pause. The earlier same-source reinstall description below describes D3/D4 only;
it does not authorize bypassing D5A guards. Existing archives have no D5A behavior.

Use the native arm64 or x86_64 archive; Rosetta is not the server baseline. Read
BUILD-INFO.json for the actual dependency minimum and separately tested OS.
These are unsigned/unnotarized development candidates until source, maintenance,
redistribution, Developer ID/notarization and native acceptance gates pass.
Ad-hoc native code signatures allow loading modified binaries; they do NOT
establish publisher authentication or Gatekeeper/notarization approval.

Verify the archive SHA-256 through an independent trusted channel BEFORE running
any helper from it. Transfer the whole archive. No target Homebrew, Git, Node,
Composer, developer tools or separate PHP/Python is needed. Do not disable
Gatekeeper globally. Quarantine/Gatekeeper approval must be tested on a separate
Mac; signing/notarization remains a release blocker.

Extract outside the installation root. In an isolated acceptance Mac only:

    tar -xzf PHILCST-VMS-VERSION-macos-ARCH.tar.gz
    cd PHILCST-VMS
    ./Install.command --allow-development

Installation needs sudo and defaults to /Library/Application Support/PHILCST-VMS.
It checks local disk, hashes, minimum OS/native CPU, state, owned jobs and ports
before mutation. Existing legacy/different-source installs refuse pending D5.
Same-source reinstall keeps shared/.env, database, accounts, images, calibration,
device assignments and completed setup. Missing keys/DB are not regenerated.
An interrupted install may need explicit D5 recovery; no rollback is certified.

An administrator privately delivers shared/setup/first-admin.txt (root-only),
completes /setup with a personal password and deletes the delivery file. No
working account/database/code is shipped. Explicitly enroll the installation public root
as described in NETWORK-SETUP.md. Then open https://philcst.vms.internal or the
Controls/Open.command shortcut. Controls also provides Status, Start and Stop.
Services run independently of the browser. Sleep/shutdown still stop monitoring.

LaunchDaemons use a hidden unprivileged _philcstvms identity for PHP/OCR/video/
scheduler. Caddy runs as root for port 443 on the inspected Mac; its private TLS
state is separate from app-writable state. Devices retains root because the existing
reader workaround adds temporary adapter IP addresses. No broad sudoers grants are installed.
Validate device access and macOS local-network/camera privacy natively; background
USB/webcam/TCC compatibility is NOT established. Native H264 RTSP/HTTP is the
intended baseline. No Tesseract executable, external FFmpeg transcoder or GPU
baseline is included; the packaged detector stays on CPU. Required files failing
readiness cause an actionable error, never a dependency download.

Run a complete application check with bundled Python:

    sudo "ROOT/current/runtime/python/bin/python3" -B "ROOT/current/app/deploy/macos/control.py" readiness --root "ROOT" --inference

Application readiness is separate from device status. Internal web/FastCGI/video
APIs stay on loopback. The default browser/media mode is this computer only.
NETWORK-SETUP.md documents explicit LAN sharing, controlled hosts/CA/firewall
changes and client-only enrollment; no OS computer rename is used. D5 must implement certified updates,
coherent backups, failure recovery and transfer. D5A adds durable persisted delivery
and maintenance controls; native acceptance is still required. Do not
use these candidates to upgrade a working school installation.
