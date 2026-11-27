# Deployment D3 — Intel and Apple Silicon offline candidates

**8 November follow-up:** automatic-update preparation is now implemented as build-only
contracts and sidecar metadata. See [D3 amendment coverage](#d3-amendment--automatic-update-preparation-follow-up)
below. The original package identities and acceptance limitations remain unchanged.

Date: 8 November 2026, Asia/Manila. Authorized scope: Mac offline candidates, manual common-source packaging workflow and D5 layout/interfaces. Named HTTPS/LAN access is D4; safe cross-platform lifecycle/durable delivery is D5. No applicable AGENTS.md was found in the checkout or ancestor directories. D1, its runtime appendix, D2, current build/target scripts and the actual detector/runtime paths were inspected before editing.

## Result and limits

Implementation, built artifacts and native offline acceptance are separate results. The two Mac packages are development/acceptance candidates, not approved releases. Both use the same captured application/build source and build identity. The final artifact table below records exact source, paths, hashes, native closure and runtime results per architecture.

The available host is native arm64 macOS **26.6.1**. Packaged arm64 runtime execution is available; Intel execution and either architecture's clean offline system-install/reboot acceptance are unavailable. Both native-library audits derive a **macOS 14.0.0 deployment minimum** from Mach-O load commands, with a 14.0 floor also enforced by the wheel selection. This is a dependency-imposed minimum, not a claim that macOS 14 was tested or certified. Sonoma bottle build labels record 14.8; those labels alone do not prove behavior on 14.0. Minimum/current OS acceptance remains required.

No working installation was changed: no live migrations/seeding/resets, user/account repair, camera/reader access or configuration changes, LaunchDaemon/user/group installation, hostname/DNS/trust/firewall mutation, deployment, project staging/commits/pushes or publication. Application migrations and synthetic accounts were used only in private fixture copies. Native code signing performed here was ad-hoc on copied package binaries only. Existing D1/D2 documents and the D2 ZIP remain intact.

## Verified D2 baseline and work completed

D2 already had source snapshot capture/dirty-release rejection, Windows hash pins, CPU/LAP tracking readiness, deterministic headless OpenCV, per-file manifests, private-data boundaries, local-only target preflight and source-preserving reinstall guards. Its Windows offline/EXE/service/reboot acceptance and publisher signature checks were still NOT RUN. Its update/restore scripts were still uncertified. The actual workflow still had a push trigger and only built Windows; this has now changed to manual-only orchestration.

D3 adds:

- A manual `offline-packages.yml` workflow selecting a full 40-character commit and one version, validating clean committed source and passing one run/attempt identity to Windows x64, Mac x86_64 and Mac arm64. The Windows workflow is now a reusable leg without a push trigger. All outputs are reviewable Actions artifacts; there is no release publication step or write permission. Windows metadata now accepts the common build identity.
- Mac source capture using the D2 boundary code, including app/build/templates/manifests/locks together. Mac cache/dist paths are excluded. Explicit development commit selections must agree with HEAD. Every captured file is hashed, with Windows script CRLF canonicalization retained so cross-platform source hashes can be compared. A development version includes the source-hash suffix. No existing work is staged or discarded.
- Separate immutable URL/SHA pins for native PHP/Python/video binaries, their supplier provenance, and both native Python graphs. Full wheel runtime closure is checked with Darwin/target-architecture markers and explicit LAP for ByteTrack. Shared Windows closure defaults retain their existing behavior.
- Complete Mac app/vendor/local frontend/HLS assets and notices, native runtimes, models/configurations and recorded fixtures. Composer runs with production lock/no development packages/no scripts. npm uses the existing lock and produces local Vite assets. Dependency caches remain build-machine-only.
- A root-owned release/shared-state layout, guarded fresh/same-source installer, LaunchDaemons, bounded child-log supervisor, Open/Status/Start/Stop controls, native/platform/data/ownership/port preflight and separate application/device readiness. No Homebrew/developer tool or separate PHP/Python is needed on the target.
- Architecture/native-library/minimum-OS inspection, loader-relative relocation, ad-hoc signature verification, full content manifests, dependency/license inventories, assembled-data/path scan, archive hashes and extracted-byte verification. The native arm64 archive also gets executable/import checks at its newly extracted location.

## Exact runtime and packaging decisions

| Component | Apple Silicon arm64 | Intel x86_64 |
| --- | --- | --- |
| PHP CLI/FPM | 8.4.24 Sonoma bottle inputs, required native dylibs relocated | 8.4.24 Sonoma bottle inputs, native Intel dylibs relocated |
| PHP native closure | 53 CLI/FPM/opcache/library files | 53 CLI/FPM/opcache/library files |
| CPython | 3.12.16, Astral standalone release 20261026, install-only stripped | Same version/release, native x86_64 archive |
| Caddy / go2rtc | 2.11.7 / 1.9.14, native arm64 | 2.11.7 / 1.9.14, native Intel |
| Python graph | 57 exact SHA-pinned distributions | 59 exact SHA-pinned distributions |
| Torch / Torchvision | 2.11.0 / 0.26.0 | 2.2.2 / 0.17.2 |
| NumPy / SciPy | 2.4.3 / 1.17.1 | 1.26.4 / 1.15.3 |
| OpenCV full/headless | 4.13.0.92, headless payload wins | 4.10.0.84, headless payload wins |
| ONNX Runtime | 1.31.0 | 1.23.2, plus coloredlogs 15.0.1/humanfriendly 10.0 |
| scikit-image / Matplotlib / contourpy | 0.26.0 / 3.10.9 / 1.3.3 | 0.25.2 / 3.10.8 / 1.3.2 |
| tifffile | 2026.5.2 | 2025.6.11, compatible with the NumPy 1.x graph |
| EasyOCR / fast-plate-ocr / Ultralytics / LAP | 1.7.2 / 1.1.0 / 8.4.41 / 0.5.13 | Same |
| Required models | Existing pinned YOLOv8n/v8s/plate YOLO, CRAFT, English G2, CCT-S-v2 ONNX/config | Same exact model hashes |
| Browser assets | Vite 7.3.7/Tailwind 4.3.5 build, Axios 1.21.0/Laravel Vite plugin 2.1.0; local HLS player/license | Same |
| Execution | CPU selected; CUDA/MPS are not the candidate baseline | CPU selected; CPU feature/performance acceptance NOT RUN |
| Optional capabilities | Tesseract executable and standalone FFmpeg transcoding unavailable | Same |

The original D2 Windows graph cannot serve Intel macOS: its Torch/ORT/NumPy/OpenCV combination has no matching native wheel set. Intel was resolved as a separate graph; a real closure check also caught the newer tifffile's NumPy>=2.1 requirement and selected the compatible pinned version above. This is a candidate compatibility solution, not a maintenance/security approval for old dependencies. [PyTorch's release notes identify 2.2.x as its final macOS x64 series](https://github.com/pytorch/pytorch/releases/tag/v2.2.0). Maintaining a supported Intel Torch path or adopting a reviewed alternative/native build remains a release decision.

PHP 8.4.24 is the inspected common Sonoma/Intel bottle baseline; newer current core versions lack the same Intel bottle. The manifest explicitly records a maintenance gate for the older PHP/dependency snapshot. This must be refreshed or security-approved before a release. No unsupported newer Intel binary or Rosetta workaround is substituted.

PHP supplier inputs are content-addressed Homebrew bottles plus their pinned SBOM/provenance, downloaded by the builder without installing them into the host's Homebrew. The native closure is copied into runtime/php and every non-system PHP dependency rewritten to a package-relative loader path. [Homebrew documents that bottles are compiled tar archives with relocation constraints](https://docs.brew.sh/Bottles); D3 does not assume they are already portable. Python uses [Astral's standalone install-only distribution](https://docs.astral.sh/python-build-standalone/distributions/), with no copied development venv. Runtime links are materialized inside the package; unsupported external links fail.

The audit thins universal files to the requested native architecture, verifies every Mach-O architecture, computes the maximum deployment target and closes non-system load dependencies inside the package. Wheel libraries with inherited RPATHs are rewritten explicitly; duplicate developer RPATHs are removed. Only macOS-provided /usr/lib and /System/Library references remain external. Native copies are re-signed and verified. Absolute supplier build constants in Python sysconfig/debug metadata are distinct from loader dependencies; no target Homebrew path is required by the audited runtime.

Environment-creation/installation helpers (pip, ensurepip, venv activation scripts, developer console launchers) are omitted; target dependency installation is not a supported operation. The assembled scan caught an unused standard-library Windows activation example, which was removed with those helpers. Mac payloads omit development autostart/sudoers helpers and Windows target scripts. Package data contains no working DB, .env, setup code, account fixtures or live device/media directories.

OpenCV distribution metadata for both required providers is retained, but only the exact headless cv2 payload is selected and verified last. Recorded image readiness executes all three YOLO models, positive vehicle detections, two ByteTrack frames with stable IDs, EasyOCR and the local ONNX recognizer, with Python networking blocked. The separate recorded video check decodes four MP43 AVI frames. This does not prove H264/RTSP, codec accuracy, live cameras or reader connectivity. Synthetic RFID verifies real R2000 split-frame/leading-zero UID/checksum decoding without physical RF or integration writes. Tesseract PATH fallback, runtime auto-install and model download attempts are disabled by the inherited D2 packaged flags/guards.

Complete exact input URLs/hashes and declared licenses are recorded in runtime/wheel lock JSON, RUNTIME-INPUTS, WHEELS-INPUTS, PYTHON-DEPENDENCIES, NATIVE-DEPENDENCIES, DEPENDENCY-LICENSES and retained notices. The inventory includes production Composer, frontend/build dependencies, HLS, PHP-library supplier SBOM, Python/native runtimes, models and recorded media. An inventory/license declaration is not an institutional redistribution grant.

## Manual build instructions and artifact identity

After reviewing and explicitly committing the intended work, open GitHub → Actions → **Manual offline package candidates** → **Run workflow**. Select a workflow ref containing the new packaging workflow, enter the full source commit SHA and one semantic version. The chosen commit must itself contain the matching packaging inputs. These workflows are local, uncommitted changes. They appear in Actions only after the owner reviews/commits them and separately approves/pushes that source to GitHub; neither was performed here. No workflow was triggered or changed remotely by this task.

The source job rejects malformed versions, abbreviated/wrong commits and dirty checkouts. Its exact commit/version/build identity feed all three platform jobs. Mac runner labels are macos-15 arm64 and macos-15-intel x86_64, checked against [GitHub's current runner reference](https://docs.github.com/en/actions/reference/runners/github-hosted-runners). The runner architecture is asserted before building. Availability, hosted-runner execution and the Windows EXE leg remain untested by this local task.

Download all three outputs from the same run's Summary → Artifacts within 14 days. Compare commit, canonical source hash, requested version, build identity, architecture and checksums. A partial failed matrix is not a completed three-platform candidate. Building and publishing remain separate; there is no GitHub Release step. Automation currently produces unsigned acceptance candidates from clean committed source, visibly marked development/NOT-RELEASE. Authenticated release production is blocked until signing/notarization, maintenance/redistribution and D4–D6 acceptance are implemented/approved.

Local build commands used:

```sh
python3 -B build/macos/build.py --arch arm64 --version 0.1.0-d3.1 --development --build-identity D3-local-20261108 --out build/macos/dist/Mac-arm64 --cache PRIVATE_BUILD_CACHE
python3 -B build/macos/build.py --arch x86_64 --version 0.1.0-d3.1 --development --build-identity D3-local-20261108 --out build/macos/dist/Mac-Intel --cache PRIVATE_BUILD_CACHE
```

Use new output folders. Build tools are macOS/Xcode command-line tools, Python/pip, Composer/PHP, Node/npm and Git; none become target prerequisites. BuildInfo records tool versions, runtime versions, architecture, minimum vs tested OS, common identity, source/file hashes, lock hashes and validation outcomes. Cross-assembly is allowed for a development candidate, with execution NOT RUN when the host architecture differs. Release mode refuses while the notarization/release pipeline is incomplete; an optional Developer ID code-signing identity hook does not override that gate.

The prior D2 Windows archive remains a separate earlier development source snapshot. Do not combine it with these D3 Mac outputs and call them one tested release set. After source approval, manually rebuild all three from the same selected commit. D6 must certify that common set.

## Installation, services and preserved state

Default root: /Library/Application Support/PHILCST-VMS. Quoted paths with spaces are supported; unsupported shell/config characters and control characters are refused. Structure:

```text
ROOT/releases/VERSION/          root-owned app/runtime/config templates/manifests
ROOT/current                   controlled link to that release (D5 future promotion interface)
ROOT/shared/.env               encryption/integration secrets, retained
ROOT/shared/database/          local SQLite and journal files
ROOT/shared/storage/           history/media/devices/calibration/runtime output
ROOT/shared/cache/             Laravel/Python writable caches
ROOT/shared/config/            generated PHP/FPM/Caddy configuration
ROOT/shared/setup/             root-only first-admin delivery
ROOT/shared/logs/              bounded service logs
ROOT/shared/installation.json  source/architecture/provisioning marker
ROOT/Controls/                 Open, Status, Start, Stop .command entries
```

Install.command first checks native architecture and minimum OS using built-in shell tools, before starting a wrong-architecture interpreter or prompting for Rosetta. It checks basic required files and then invokes the bundled control helper through sudo. Verify the externally trusted archive checksum before executing any supplied helper. A self-checked archive cannot establish publisher trust.

Before installation-data/service changes, preflight checks complete manifest hashes/extra files, platform/native architecture, elevation, local APFS/HFS storage/space, root/state redirects, existing configuration/SQLite/key paths, service user/group ownership, existing launchd labels/plists, conflicting collectors and TCP/UDP ports. Internal browser port is 8080, PHP-FPM 9001, go2rtc API 1984/WebRTC 8555, detector 8765. Conflicts fail without terminating unrelated processes. Group-only/incomplete or mismatched service identities are refused before any account mutation.

Fresh installation creates installation-specific APP_KEY/API secret, a local empty DB, non-account InstallSeeder defaults and the existing protected first-admin setup flow. It does not ship demo credentials or automatically choose a personal password. The administrator delivers the expiring root-only file privately, completes /setup and deletes the file. Shared state is separate from code; controlled generated links connect app storage/cache/environment to it.

Six system LaunchDaemons run PHP-FPM (four workers), Caddy, go2rtc, detector, Devices and the minute scheduler. They use explicit bundled paths/working directories, launchd throttling/restart and a child supervisor with 10 MiB logs plus five rotated files. Caddy access logs also rotate; PHP's own error log and launchd supervisor-failure logs need a final operational retention policy. Services continue without a browser; sleep/shutdown still interrupt monitoring.

A hidden non-login _philcstvms user/group owns writable state. Web/PHP/OCR/video/scheduler use that unprivileged identity; only Devices remains root because the existing reader workaround adds/removes temporary local adapter addresses. Root Devices shares the service group and an appropriate umask for shared state. No broad sudoers rule is installed. Code remains root-owned/read-only to the service. Setup delivery stays root-only. Start/Stop/privileged Status shortcuts use sudo; application readiness and device-status freshness are reported separately. Native launchd/account/permission/device-privacy acceptance remains NOT RUN.

Fresh and SAME-SOURCE reinstall are supported by the implementation. Reinstall reuses .env, DB/accounts, activity/media, assignments/calibration, keys and provisioning. It does not reseed or reopen setup. Legacy/non-SQLite/different-source/architecture installations, missing keys/database and interrupted initializing markers refuse for explicit D5 recovery/update. This conservative refusal is not a completed updater or rollback mechanism. No uninstaller/data deletion feature is introduced by D3.

For isolated acceptance only, transfer the complete trusted archive, extract outside ROOT, then run ./Install.command --allow-development. Controls/Open.command opens http://127.0.0.1:8080. Run the bundled control.py readiness --inference with sudo for hashes, PHP/SQLite/native/OCR/video/protocol checks. See build/macos/OFFLINE-INSTALL.md for commands and build/macos/NATIVE-ACCEPTANCE.md for the exact native matrix.

## Tests actually run and limitations

All writes used private databases/storage/configuration/install-layout fixtures. The original working installation and physical devices were not test targets.

| Check | Executed result |
| --- | --- |
| Mac policy/manifest/input/layout/ownership tests | 19 passed |
| Shared D2 package regression suite | 14 passed |
| Full isolated Laravel suite | 815 tests, 8,676 assertions passed on host PHP 8.5.3 |
| Latest Windows installer/release-boundary filter | 10 tests, 98 assertions passed after shared metadata/source helper changes |
| PowerShell pure helpers / preflight | 17 + 13 passed on macOS PowerShell 7 |
| PowerShell parsing | 23 source scripts parsed; zero syntax failures |
| JavaScript unit tests | 17 passed |
| Browser Registry status/owner | 83 isolated Chrome checks passed |
| Browser optional RFID | 43 isolated Chrome checks passed |
| Existing Python Windows-kit / snapshot fixtures | 4 + 5 passed in the private test copy |
| Both frozen frontend builds | npm ci/Vite production builds passed |
| Packaged arm64 PHP/FPM | PHP 8.4.24 required extensions, SQLite 3.53.4, real Argon2id and FPM configuration passed |
| Packaged arm64 application fixture | Production Composer dependencies, isolated fresh migrations/InstallSeeder, two gates, protected first-admin personal setup/login and repeat setup no-new-code passed; one synthetic user, no working data |
| Packaged arm64 native inference | Actual bundled Python 3.12.16, native imports, CPU models/prediction/tracking/OCR passed on macOS 26.6.1; separate from system install |
| Packaged arm64 recorded evidence | Four local MP43 AVI frames decoded; synthetic R2000 split frame, leading-zero UID and checksum checks passed |
| Arm64 Caddy/go2rtc | Caddy config validation and go2rtc 1.9.14 native version passed; no services installed |
| Native-library audit | Both architectures inspected; 14.0.0 Mach-O minimum, no unresolved non-system loader dependencies; native code signatures verified ad-hoc |
| Final archive verification | Per-file stage/hash scan and actual full extraction verification; final counts/hashes below |
| Preservation | Staged index unchanged; existing application edits/D1/D2 data preserved, only listed packaging overlaps changed |

An initial private Laravel copy lacked its writable bootstrap/cache directory; the copy was fixed and the complete rerun passed. This was fixture setup, not a working-install repair. Other verified build issues fixed before final assembly: duplicate PHP SBOM entry, incompatible Intel tifffile/NumPy requirement, wheel RPATH inheritance/duplicates, unused venv activation payload, quoted Mac paths and service-group ownership. These were not hidden by loosening application identity/security rules.

NOT RUN: native Intel executable/import/model/video behavior; either architecture on macOS 14/minimum OS; clean machine without developer tools and WAN disconnected; actual sudo installation/DSCL/LaunchDaemon creation/start/stop; native filesystem ownership as the service user; packaged system first-admin setup/login; reboot/logged-out/browser-closed persistence; same-source system reinstall/fault recovery; Gatekeeper/quarantine/notarization offline acceptance; real camera RTSP/H264/ONVIF/video/browser streams, physical RFID/USB drivers/webcam/local-network/TCC permissions; LAN clients/HTTPS; D5 update/backup/restore/transfer/outbox failure cases. Native arm64 fixture execution does not prove these.

The old classification-ui fixture mismatch noted by D1/D2 remains outside this batch. It was not weakened or claimed green. Both workflow YAML files were parsed locally (dispatch/call only); PowerShell and Python entry points parsed/compiled. The Actions workflows were reviewed locally but not pushed or executed. No .pkg/.dmg, Developer ID authenticated archive or notarization ticket was produced; the delivered installation candidates are complete tar.gz archives with a guarded command installer.

## Remaining signing, maintenance and redistribution gates

- Current native code is ad-hoc signed solely to load relocated/thinned copies. It is not publisher-authenticated. Developer ID/notarization/stapling and disconnected Gatekeeper behavior require credentials, reviewed distribution format and native acceptance. Never disable Gatekeeper globally to claim success.
- Intel's older Torch 2.2.2/NumPy-compatible graph needs an approved maintenance/security strategy and native model parity/performance testing. The PHP 8.4.24/historical library baseline also needs refresh/security approval. No untested support for every Intel CPU is claimed.
- Institutional redistribution review must cover application/dependency obligations, Ultralytics/YOLO/recorded image AGPL terms, third-party model provenance, Python/native/codec notices, PHP-library supplier licenses and the added recorded OpenCV video. Shipping metadata is not approval or a source-offer determination.
- Source remains a development working snapshot until explicitly reviewed/committed by the owner. No automatic staging/commit is performed. All final platforms must be rebuilt at one reviewed D6 source identity.
- D4 named HTTPS/client access and D5 consistency/durable delivery/recovery remain release gates, even when local runtime checks pass.

## Built artifacts, source identity and checksums

The implementation below is complete as a D3 **development candidate** for each architecture, with the shared native/signing/maintenance gates above. This is not a production-support certification.

| Architecture | Candidate implementation | Complete archive built | Packaged native runtime | Clean offline system install acceptance |
| --- | --- | --- | --- | --- |
| Apple Silicon arm64 | Implemented | Yes; extracted and hash-verified | Passed on arm64 macOS 26.6.1, including extracted-path imports | **NOT RUN** |
| Intel x86_64 | Implemented | Yes; extracted and hash-verified | **NOT RUN**; architecture/library/minimum-OS structural audit passed | **NOT RUN** |

Base commit: `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`. Version: `0.1.0-d3.1-dev.66c18ba2ecda`. Common build identity: `D3-local-20261108`. Canonical source SHA-256: **`66c18ba2ecda68cb7d3e4464d8e24cd54acbfb7dde44068e7845c4cf06148fc9`**; 726 frozen source files. Both artifacts contain the same source manifest. The staged project index is unchanged.

### arm64

- Archive: `build/macos/dist/Mac-arm64/PHILCST-VMS-0.1.0-d3.1-dev.66c18ba2ecda-macos-arm64.tar.gz`.
- Size: **497,589,601 bytes** (474.5 MiB).
- Archive SHA-256: **`01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80`**.
- Checksum sidecar: same archive path plus `.sha256`.
- Extracted stage: `build/macos/dist/Mac-arm64/PHILCST-VMS/`.
- Content manifest: **29,740 files**, **1,436,301,447 uncompressed file bytes**.
- Audited native files: **427**; derived Mach-O minimum **14.0.0**.
- Content-manifest SHA-256: `ef9379aaf04ef631b9085d94e07b7a10f77885b4389ea9dab01f7219280118a9`.
- BUILD-INFO SHA-256: `fe4730b3108a12472bdaf3cb5e7b2ca79ca72c31b961f38978bd2490b4a6cf70`.
- Native runtime: `passed on 26.6.1`; CPU inference: `recorded prediction/tracking/OCR passed`; offline installation: `NOT RUN`.

### x86_64

- Archive: `build/macos/dist/Mac-Intel/PHILCST-VMS-0.1.0-d3.1-dev.66c18ba2ecda-macos-x86_64.tar.gz`.
- Size: **618,013,482 bytes** (589.4 MiB).
- Archive SHA-256: **`e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95`**.
- Checksum sidecar: same archive path plus `.sha256`.
- Extracted stage: `build/macos/dist/Mac-Intel/PHILCST-VMS/`.
- Content manifest: **27,965 files**, **1,790,464,773 uncompressed file bytes**.
- Audited native files: **410**; derived Mach-O minimum **14.0.0**.
- Content-manifest SHA-256: `eceeab8f2fe340c711e85bebe1366063ff3ce878a8261cd40c15638ecd88b86b`.
- BUILD-INFO SHA-256: `10c45a625e07ca7c7c0df52d17bb4c62952a3c99093df2af3b0e86c41ac29e06`.
- Native runtime: `NOT RUN`; CPU inference: `NOT RUN`; offline installation: `NOT RUN`.

The final assemblies passed the shared installation-data/development-path scan and content verification. Both actual tar.gz files were fully extracted and every manifest file rehashed; extracted test folders were removed afterward. The arm64 extracted copy also executed its own PHP and Python/native imports from the relocated path. Neither payload contains an installed .env, working database or first-admin delivery file. The target does not need the build cache, Homebrew or developer tools.

Ad-hoc signatures are recorded as such; publisher_authenticated remains false and notarization remains NOT RUN. No .pkg/.dmg or authenticated release is claimed. This report was written after the snapshot/build, so its post-build hashes/prose are not recursive inputs to those source manifests. Future committed builds will correctly have a new source identity. Generated archives/stages are Git-ignored. Failed/superseded D3 stages and disposable extracted-PHP caches were removed; source pins, checksums and private evidence logs remain.

## Complete D3 changed-file list

Compared with the recorded D3 starting workspace; prior registration/D1/D2 changes are preserved. There are **no new migrations**, no application behavior/schema edits, and no working-data changes.

Modified existing packaging files:

```text
.github/workflows/windows-kit.yml
.gitignore
build/windows/assemble.ps1
build/windows/build-release.ps1
build/windows/package.py
```

Added files:

```text
.github/workflows/offline-packages.yml
DEPLOYMENT-D3.md
build/macos/NATIVE-ACCEPTANCE.md
build/macos/OFFLINE-INSTALL.md
build/macos/README.md
build/macos/build.py
build/macos/checks/evidence.py
build/macos/requirements-arm64.lock
build/macos/requirements-x86_64.lock
build/macos/runtime.py
build/macos/runtimes-arm64.json
build/macos/runtimes-x86_64.json
build/macos/templates/Caddyfile.template
build/macos/templates/env.template
build/macos/templates/php-fpm.conf.template
build/macos/templates/php.ini.template
build/macos/wheels-arm64.json
build/macos/wheels-x86_64.json
deploy/macos/control.py
deploy/macos/service_runner.py
tests/macos/test_offline_package.py
```

The two content manifests list every generated native/app/model/asset file. Generated archives and build caches are not staged source files.

## HANDOFF FOR DEPLOYMENT D4 AND D5

**D4:** Keep this phase's internal loopback service boundaries while implementing the common named HTTPS address/client trust workflow. Use the configured go2rtc webrtc_host hook and distinguish the actual MJPEG bind variable from the exported client stream host. Review authenticated live/SDP/HLS/MJPEG and historical media serving, secure cookie/proxy behavior, certificate storage, offline renewal and narrowly scoped transport/firewall rules. No hostname rename, .local workaround, hosts mapping, CA import or LAN client feature was installed by D3. Test actual Windows/Mac clients and browsers; the local-only runtime check is not D4 completion.

**D5:** Preserve the releases/current/shared layout and installation marker, including APP_KEY/API secret, local DB/accounts/setup state, history/media, gates/assignments/calibration and lost-UID permanence. Implement a maintenance/ingestion fence; stop/drain collectors and scheduler before a coherent data/media/keys backup; stage a compatible code/runtime/data set; run migrations against copies; validate schema, SQLite integrity, decryption, manifests and media before atomic promotion. Gate service restart on successful completion, and implement tested rollback/failure recovery without orphaned Review/audit relationships or new movement effects. Reconcile monotonic denylist/security state when restoring older snapshots. Keep one active collector/server owner during transfer.

Windows D2 still refuses legacy/different-source reinstall, and its old update/backup/restore consistency, task-fencing, partial-swap/restore and volatile delivery defects remain unresolved. Mac D3 likewise refuses changed-source, legacy and interrupted installations rather than pretending to update or recover them. No cross-platform updater, backup/restore, uninstaller/transfer or durable outbox is certified by D3. Define D5 source/schema/runtime compatibility manifests and a protected installation epoch/outbox handoff for both Windows and Mac. Never silently convert non-SQLite or replace missing encryption keys.

**D6:** Rebuild Windows, Intel and arm64 from one reviewed clean commit/version, then perform the native minimum/current OS matrix, offline no-development-tools installation, personal setup/login, reboot/browser-closed operation, native permissions/privacy, model/video/RFID and failure/reinstall/recovery acceptance. Resolve Intel/PHP maintenance, publisher signing/notarization, redistribution/source notices and final log retention. Refresh obsolete project LAN/recovery instructions. The older D2 ZIP and current D3 Mac archives are development artifacts from different source snapshots and cannot be represented as one final release set. Publication/push remains a separate authorized action.

Private evidence pointer: `/tmp/philcst-deployment-d3-workspace.txt`. It identifies `delivery-arm64.log`, `delivery-Intel.log`, `final-artifacts.json`, native/packaged PHP/inference/evidence/application fixture logs, Laravel/browser/JS/Python logs, preservation audit and pinned build cache. Full-suite JUnit: `/tmp/philcst-d3-laravel.xml`. These are local test/build evidence, not installation backups or release inputs.

**Stop after D3. Wait for Go Deployment D4.**

## D3 AMENDMENT — Automatic Update Preparation follow-up

Completed locally on 8 November 2026, Asia/Manila. This section records the follow-up
separately from the original D3 build/runtime checks above. Source inspection found
existing content manifests, whole-package hashes, Windows content-signature/EXE hooks,
manual common-source builds and preserved-state layouts. It did **not** find an update
manifest, channel/version selector, installation-trusted update-key interface or their
tests. Missing report evidence was checked against source before implementation.

### Amendment coverage

| Requirement | Inspected baseline | Follow-up result |
| --- | --- | --- |
| Versioned platform update manifests | Missing; content manifests were present | Implemented `philcst.update.v1` for Windows x64, Intel Mac and arm64 Mac |
| Common release identity | D3 manual build identity present | Metadata matrix enforces all three platforms and identical version/channel/commit/source hash/build identity |
| Version/channel/platform/origin contracts | Missing for updates | Implemented strict SemVer, explicit channels, installed-version checks, OS/architecture and compatibility rejection |
| Trusted signatures and rotation interface | Existing D2 signing was a separate content/EXE mechanism | Implemented local public trust store, RSA verification, scoped/revoked keys and signed rotation **proposal** validation |
| Package/archive validation | Existing D2/D3 content helpers present | Reused their manifest/build formats; added streaming archive binding, path/content/identity checks without extraction |
| Downloaded and offline-import validation | Missing | One read-only validator and PowerShell wrapper, independent of delivery source |
| Manual metadata generation | Missing | Both builders emit sidecars; workflow uploads metadata and aggregates a review matrix |
| Production authentication/trust bootstrap | No credentials/root supplied | **Blocked:** production publisher keys, installer-provisioned roots, protected signing and native acceptance remain required |
| Upgrade authorization | D5 migration/recovery not implemented | **Blocked by default:** no supported origins or accepted schema/runtime fingerprints are authorized |
| Background checks/downloads, Update UI, installation/recovery | Outside D3 | **Deferred to D5; not implemented or executed** |

### Manifest and compatibility contract

Exact schema: [build/updates/update-manifest.schema.json](build/updates/update-manifest.schema.json).
Authoritative semantic validation: [build/updates/contract.py](build/updates/contract.py).
Full synthetic example: [build/updates/example.update.json](build/updates/example.update.json).
The following is that example in compact form; hashes, package and version are invented,
and the empty signatures mean it cannot pass trusted validation:

```json
{
  "signed": {
    "schema": "philcst.update.v1", "product": "philcst-vms",
    "version": "1.1.0-rc.1", "channel": "prerelease",
    "source": {
      "commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "build_identity": "SYNTHETIC-EXAMPLE-NOT-A-RELEASE"
    },
    "platform": {"id": "macos-arm64", "os": "macos", "architecture": "arm64", "minimum_os": "14.0.0"},
    "compatibility": {
      "supported_upgrade_origins": [{"minimum_inclusive": "1.0.0", "maximum_exclusive": "1.1.0"}],
      "schema": {"accepted": ["cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"], "target": "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"},
      "runtime": {"accepted": ["eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"], "target": "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"},
      "installation_phase": "D5"
    },
    "package": {
      "filename": "SYNTHETIC-PHILCST-1.1.0-rc.1-macos-arm64.tar.gz", "format": "tar.gz", "size": 12345,
      "sha256": "1111111111111111111111111111111111111111111111111111111111111111",
      "content_manifest_sha256": "2222222222222222222222222222222222222222222222222222222222222222"
    },
    "issued_at": "2026-11-08T00:00:00Z", "expires_at": "2026-12-08T00:00:00Z"
  },
  "signatures": []
}
```

The other platform identities are `windows-x64/windows/x64` and
`macos-x86_64/macos/x86_64`. Complete Windows ZIPs are update-import candidates; Setup EXEs
retain their separate installer purpose. Windows metadata currently carries the existing
Windows 10 floor (`10.0.0`); Windows build-number/native acceptance must be refined by
D5/D6. Both existing Mac metadata files retain their inspected 14.0.0 minimum.

Stable is the default channel. Prerelease/development require explicit channel selection
and a prerelease version; they cannot silently enter stable selection. SemVer comparison
preserves numeric prerelease ordering and ignores build metadata for precedence. An equal
version is already installed only when its exact version string, source/build and target
schema/runtime identities agree. Same-version replacements and ordinary downgrades fail.

Newer candidates require an explicit supported-origin version range plus accepted installed
schema/runtime fingerprints. Schema fingerprints bind the frozen migration path/hash map;
runtime fingerprints bind platform, runtime versions and dependency-lock hashes. They are
source compatibility identifiers, not proof of an installed database's schema/integrity.
[compatibility.json](build/updates/compatibility.json) deliberately leaves all origins and
accepted fingerprints empty until D5 certifies the upgrade path. Signing alone cannot
override those refusals.

Content and update manifests are distinct: the inner content manifest inventories payload
files, while the external update envelope binds the complete archive hash/size, that inner
manifest hash and release identity. Update metadata never enters the old archive or its
content manifest. All hashes are checked against actual streamed archive bytes.

### Trust, rotation and shared validation interface

The installation supplies a protected `philcst.update-trust.v1` public store and
`philcst.installation-update-state.v1` state. The verifier never accepts a public key from
candidate metadata. Initial trust must come from an authenticated installer or separately
verified administrator procedure. D3 does not install such a store or open live state.

Signature v1 is RSA-PKCS1-SHA256, at least 2048 bits, over sorted compact ASCII canonical
JSON of `signed`. Key IDs are SHA-256 of public SPKI DER. The installation-trusted PHP
helper requires OpenSSL verification to return exactly 1. Store keys have channel/platform
scope, update/rotation roles, validity times and revocation state. Unknown/revoked/expired,
unsigned, tampered and unsupported-algorithm metadata fail. Fixture trust needs an explicit
flag and fixture-marked state/store; fixture keys are generated temporarily and removed.
No private key or production-trusted fixture root was shipped.

`validate_rotation` checks an existing authorized rotation signature, current-store hash,
strictly next generation, expiry, replacement scope and an active remaining update key.
The returned `authorized-trust-proposal` has `trust_written: false`. Self-supplied roots and
replayed generations fail. D5 still must provide admin approval, atomic protected trust
writes, monotonic rollback/revocation persistence and independently authenticated emergency
recovery. Rotation is not applied by this amendment.

`validate.py` and `validate.ps1` expose one interface for `local` (USB/disk) and `downloaded`
staged packages. They require explicit installation-trusted Python/PHP, state and public
store; never use tools/state supplied by the incoming candidate. On Windows, a trusted PHP
ini can load dynamic OpenSSL. No target openssl CLI is required. The build signing hook
uses build-host OpenSSL and rejects repository-local private signing-key paths.

Both delivery modes enforce metadata shape/expiry, trusted signature, version/channel,
OS/architecture, origins and schema/runtime compatibility, then archive filename/size/hash,
content hash, full file inventory and BUILD-INFO identity. Streaming validation performs no
extraction or candidate execution. It rejects traversal/absolute paths, links/devices,
encrypted ZIPs, Windows reserved names, case/Unicode-normalization collisions,
file/directory shadowing, unexpected or missing files and expansion beyond local limits.

`select_candidate` only evaluates supplied envelopes; it is not network discovery. An empty
or unavailable discovery result leaves the installed version in place. No application
startup/monitoring path calls these helpers, so lack of update discovery cannot interrupt
offline use. Read-only success means eligible for **D5 review**, never installed.
See [the interface guide](build/updates/README.md) for exact state/trust shapes and commands.

### Changed files in this follow-up only

Modified existing D3/shared packaging files (earlier unrelated edits preserved):

```text
.github/workflows/offline-packages.yml
.github/workflows/windows-kit.yml
.gitignore
DEPLOYMENT-D3.md
build/macos/build.py
build/windows/assemble.ps1
build/windows/package.py
```

Added source files:

```text
build/updates/README.md
build/updates/compatibility.json
build/updates/contract.py
build/updates/example.update.json
build/updates/generate.py
build/updates/signature.php
build/updates/update-manifest.schema.json
build/updates/validate.ps1
build/updates/validate.py
tests/updates/test_contract.py
```

Generated, Git-ignored metadata only:

```text
build/updates/dist/existing-windows-x64.update.json
build/updates/dist/existing-macos-x86_64.update.json
build/updates/dist/existing-macos-arm64.update.json
```

No application/controller/model/view/schema/device/service behavior changed. There are no
new migrations, account changes, active secrets, live data repair or updater execution.

### Follow-up checks actually run

| Check | Result |
| --- | --- |
| New isolated update-contract suite | **29 passed**: JSON parsing; SemVer/channel/newest selection; all platforms/OS/origins/contracts; same-version/downgrade; trusted signatures and signing-key mismatch; tampering/revocation/expiry; missing/truncated packages; safe ZIP/tar paths and inventory; local/downloaded CLI equivalence; PowerShell wrapper with spaced paths; read-only rotation/replay/revocation; common matrix; sidecar boundaries |
| Existing D2 package regression suite | **14 passed** |
| Existing Mac package/policy suite | **19 passed** |
| Windows common/preflight helpers | **17 + 13 passed** on macOS PowerShell 7 |
| PowerShell source parser | **24 scripts parsed**, no syntax errors; includes new wrapper |
| Focused Laravel Windows/release-boundary checks | **10 tests, 98 assertions passed**, host PHP 8.5.3, private D3 fixture app/database/storage |
| PHP signature helper | Syntax check passed; real PHP OpenSSL verifies ephemeral fixture signatures |
| Both workflow YAML files | Parsed locally; dispatch/call only; embedded source-validation Python parsed |
| Actual existing archives | All three streamed and content-verified; 28,092 Windows, 29,740 arm64 and 27,965 Intel content files |
| Existing mixed-source matrix | Correctly rejected D2 Windows + D3 Macs; no matrix output written |
| Preservation | Existing archive hashes/sizes unchanged; staged index unchanged; baseline application/data/config files unchanged |

Fixtures hash their synthetic state/secret/service-config files before and after validation,
discovery and rotation; bytes remain unchanged. New tests generate private RSA keys only in
temporary directories and clean them up. Full Laravel/browser/frontend/inference suites
listed earlier were **not rerun for this packaging-only amendment**; their earlier results
remain historical. Native Windows PowerShell 5.1/PHP/OpenSSL execution and either platform's
installed update validation are NOT RUN. No workflow was pushed/triggered or remote service
changed. Production signing, trusted-root provisioning, native update execution, offline
installation/reboot and all D5 lifecycle failure cases remain unverified.

### Artifact validity and source identities after the amendment

The existing packages remain byte-for-byte valid **original development candidates**, with
the original limitations. No runtime/model/application payload input changed, so the large
archives were not rebuilt just to add build-only metadata. Backfill uses their actual frozen
BUILD-INFO, not the current generator's source identity. Every unsigned backfill also retains
empty upgrade-compatibility approval; none is a production-installable update.

| Package | Preserved version/source/build identity | Preserved archive SHA-256 |
| --- | --- | --- |
| Windows x64 D2 ZIP | `0.1.0-d2.1-dev.092593dd1048`; source `092593dd104894ad8d0236c292b3129eb3590e8998484248d47f11674041a4d5`; legacy build identity falls back to that exact version | `4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc` |
| Mac arm64 D3 tar.gz | `0.1.0-d3.1-dev.66c18ba2ecda`; source `66c18ba2ecda68cb7d3e4464d8e24cd54acbfb7dde44068e7845c4cf06148fc9`; `D3-local-20261108` | `01cdbcdf56d24d8ed843b401e3fba5f097411737be56aa2fb700dee797677a80` |
| Mac Intel D3 tar.gz | Same D3 version/source/build identity as arm64 | `e92ea889aa1222ccb0b54bdf1a046c4cff0ea46821851f81e302bb668861de95` |

Sizes remain 576,413,086 / 497,589,601 / 618,013,482 bytes respectively. All retain base
commit `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`; current work remains uncommitted.
These archives do **not** represent the newly amended source snapshot and contain no
installed update verifier/trust store. They are stale if described as current amended-source
or D5-update-ready builds. Source manifests capture build tooling too, so a future clean
rebuild must get a new canonical source identity even with identical runtime pins.
No old identity was rewritten. The prior Windows and Mac candidates cannot form one
release matrix. After source approval, rebuild all platforms from one reviewed commit and
version; use the new manual metadata/matrix pipeline and repeat required acceptance.

### Explicit D5 checklist

- Provision an authenticated, protected verifier/runtime, public root store and installed
  compatibility state. Define signed discovery/index provenance, channel selection, trusted
  time, metadata replay/freeze handling, revocation and durable key-generation state.
- Implement optional bounded background checks/downloads with failure/backoff and offline
  continuity. Stage complete packages separately; verify with the same USB/local interface.
  Handle interrupted downloads, size limits and retention without touching active data.
- Require authorized admin confirmation against a concrete validated update and recovery
  plan. No incomplete Update button or implicit installation is introduced in D3.
- Fence ingestion and all writers; stop/drain collectors, scheduler and queued work. Preserve
  one active installation owner/epoch and a durable outbox across transfer/restart.
- Back up a coherent database, media, keys, assignments/calibration, accounts/setup and audit
  state. Verify restore viability, encryption keys and monotonic lost-UID/security deny state.
- Certify upgrade origins/schema/runtime pairs. Run migrations on copies, verify ledger,
  SQLite integrity/decryption/media/manifests, then promote a complete compatible set.
- Implement atomic promotion, startup gating, tested rollback and recovery after crash,
  power loss, partial swaps, failed migration/restore and reboot. Never reset/reseed or
  silently replace keys to make an update pass.
- Run native Windows/Intel/arm64 minimum/current OS acceptance, authenticated signing and
  trust-rotation/revocation/recovery tests. D4 HTTPS/client access remains a separate phase.

Follow-up evidence directory: `/tmp/philcst-d3-update-prep-workspace.txt` points to local
test, archive-backfill, matrix-rejection and preservation logs. Focused Laravel evidence is
`d3-update-followup-windows.txt` under the original D3 private workspace. These are test
artifacts, not backups or publisher trust material.

**D3 follow-up complete. Stop and wait for “Go Deployment D4.”**
