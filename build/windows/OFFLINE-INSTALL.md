# Windows complete offline candidate — named HTTPS, local by default

**Current D5A source:** fresh installation initializes protected delivery/maintenance
state. Reinstall, legacy backup/restore/update/uninstall and the old Stop shortcut
refuse for that installation. Use MAINTENANCE.md Enter/Verify/Resume. The daily backup
task is disabled until D5B provides a coherent engine. The D2 same-source reinstall
description below is historical, and existing archives contain no D4/D5A behavior.

This is a native Intel/AMD x64 Windows 10+ server candidate. ARM64/emulation and
32-bit server installation are rejected. D4 source adds named HTTPS and small browser-client helpers; safe update/restore/
transfer remains D5. Older D2/D3 archives do not contain D4 changes.

A development candidate is unsigned/unapproved unless its manifest and installer
were explicitly signed. SHA-256 integrity is not publisher authentication.
Verify the ZIP/EXE checksum against an independently approved value BEFORE
extracting/running any supplied helper. For a signed release also verify the
publisher certificate thumbprint and Authenticode signature. Never run a helper
from an untrusted archive merely to ask that same archive whether it is trusted.

No target Git, Composer, Node/npm, Python, PHP, XAMPP or internet downloads are
needed. Transfer the whole candidate by USB or other approved means.

## Isolated candidate installation

Use an isolated Windows VM for acceptance, not the working installation. In
Windows PowerShell **as administrator**, after checksum verification:

```powershell
Expand-Archive -LiteralPath .\PHILCST-VMS-VERSION-win64.zip -DestinationPath .\candidate
$payload = (Resolve-Path .\candidate\PHILCST-VMS).Path
& "$payload\app\deploy\windows\install-package.ps1" -Payload $payload -Root C:\PHILCST-D2-Test -AllowDevelopmentCandidate
```

For a signed release use `-ExpectedPublisherThumbprint APPROVED_THUMBPRINT` instead
of the development switch. EXE candidates run the same preflight before copying
application files. Native architecture, elevation, local fixed disk/space,
manifest hashes, existing DB/keys, owned services/tasks and ports are checked.
Conflicts are reported; unrelated services/processes are never killed.

D2 permits fresh install and SAME-SOURCE reinstall. It refuses legacy or
changed-source upgrades: those require the D5 workflow. Reinstall preserves
`.env`, database/accounts, keys, media, devices/calibration and setup state.
A partial failure is reported with services possibly stopped; data is retained.
Do not confuse this with a certified rollback mechanism. Review logs and rerun
only the same verified candidate. Do not reset/reseed or generate replacement keys.

The operator delivers the expiring first-admin code from protected
`config\setup\first-admin.txt`, completes `/setup` with a personal password, then
deletes that delivery file. There are no shipped login credentials. A completed
setup is never reopened automatically. Accounts and owner activation remain the
existing application workflow.

Open **Open PHILCST VMS** after the explicit trust enrollment in NETWORK-SETUP.md.
Current source defaults to loopback-only browser/media bindings. LAN sharing is an
explicit operator choice. Do not rename the PC or advertise `.local`; the browser
address is https://philcst.vms.internal. The old D2 archive remains a local HTTP
candidate and must not be described as containing this D4 implementation.

## Readiness and daily operations

```powershell
& C:\PHILCST-D2-Test\app\deploy\windows\status.ps1 -Json
& C:\PHILCST-D2-Test\app\deploy\windows\readiness.ps1 -VerifyContent -Inference -Json
```

Start/Stop shortcuts/scripts manage only services owned by this root. Monitoring
runs independently of the browser. Sleep, shutdown and unavailable network/device
permissions still stop or limit monitoring; manage the server's power settings
explicitly. D2 does not change all network/power profiles automatically.

Services use bundled paths, restart/backoff and rotated logs. PHP/detector/video use LocalService; Caddy uses its own NT SERVICE\PHILCST-Web
identity with separately protected TLS state; Devices remains LocalSystem because the existing
cross-subnet reader workaround configures temporary local adapter addresses.
State is writable; code and secrets have restricted ACLs. Validate these ACLs and
actual device access on Windows before operational approval.

The readiness fixture loads all three YOLO models, two-frame ByteTrack and EasyOCR and the ONNX plate
recognizer with networking blocked and CPU-only Torch. It checks imports and
positive recorded-vehicle inference, not Philippine plate accuracy or hardware.
Required models/dependencies missing or corrupt cause a clear refusal; no runtime
fetch is attempted. Device readiness is separate from application readiness.

Tesseract and standalone FFmpeg transcoding are **not included/supported by D2**.
Packaged OCR disables Tesseract PATH fallback. Native H264 RTSP/HTTP video and
bundled ONNX/EasyOCR remain the supported baseline; H265-only/transcoded/browser-
unsupported codecs require separate reviewed packaging. GPU/CUDA is not supported
by this CPU candidate. A native runtime pass is not hardware/OS acceptance.

## Removal, recovery and release gates

Uninstall defaults to retaining database, accounts, keys, media and provisioning
state. Only owned services/tasks and unchanged manifest-listed program files are
removed; D2 creates no LAN firewall rules to remove. Explicit data removal remains
a separate choice. Preserve retained package metadata for SAME-SOURCE reinstall.

Existing backup/update/restore scripts are NOT certified by D2. Their D1/D5
consistency, partial-restore, writer-fencing and outbox findings remain release
gates. Do not use this candidate as authorization to upgrade a working deployment.
Native Windows 10/11 offline, reboot, device and signing acceptance must be
recorded against the actual artifact checksum. See the accompanying D2 report.

For D4 builds, use NETWORK-SETUP.md after installation to explicitly verify and
enroll the generated installation root, then open https://philcst.vms.internal.
Fresh installs default to this computer only. LAN sharing requires explicit mode
selection; Connect-to-Server contains client-only Windows/Mac instructions.
Never use the internal HTTP integration listener as the browser address.
