# Deployment D2 — Windows complete offline candidate

Date: 8 November 2026. This report implements the authorized D2 request against `DEPLOYMENT-D1.md` and `DEPLOYMENT-D1-RUNTIME-INVENTORY.md`. No applicable `AGENTS.md` was present in the repository or its ancestor directories. D1's mixed-source builder, mutable VC++ download, overlapping OpenCV payload, missing frontend lock, broad LAN setup and elevated service findings still applied at inspection.

**Implementation completed:** frozen source capture, hashed offline dependencies, deterministic OpenCV provider, content/inventory manifests, local-only installer/preflight/services, readiness probes, signing hooks and native acceptance procedure. **Artifact built:** the development ZIP identified below. **Native Windows acceptance: NOT RUN.** These are separate results. The EXE bootstrapper is prepared in source; its compilation/execution and a signed release remain blocked by Windows/signing/approval access. No release was published.

## Source, preservation and scope

The starting HEAD remains `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`. All 35 pre-existing modified/untracked paths were compared with the D2 starting snapshot and remained byte-identical, including Add Vehicle/printed-tag changes and the D1 documents. No project staging/commits/pushes, working-database resets/migrations/seeding, production repairs, device configuration changes, deployment or external-service mutation was performed. Package tests create and remove synthetic Git repositories only in private temporary directories. The original database, media, `.env`, accounts and models were not used as test mutation targets.

Release mode requires a clean checkout at the explicit requested commit, redistribution approval, a Windows signing certificate and native runtime checks. Dirty releases fail before source export; the actual dirty working checkout was also checked and refused without creating a snapshot. Development mode captures tracked plus allowed untracked working files, freezes app/scripts/templates/lockfiles together, verifies the captured files again, and records every source hash. Windows scripts are canonicalized to CRLF, explicitly recorded in `SOURCE-INFO.json`. New/deleted/changed source files during capture cause refusal. Development snapshots are labeled NON-RELEASE and include the source hash in the version.

This report was written after the final snapshot. Its post-build prose is not an input to that artifact; application/build/dependency inputs are the frozen hashes in its source manifest. D1 reports and prior application changes existed before D2 and are not claimed as newly implemented here.

## Implementation and dependency decisions

- `build-release.ps1` captures one external snapshot and invokes only its frozen assembler. It does not mix HEAD application files with current packaging files. Existing output stages are refused rather than deleted. Assembly never boots Laravel, reads installation secrets or seeds accounts.
- A new npm lock makes `npm ci` and the Vite build repeatable from dependency inputs. Production Composer installation follows the existing lock with `--no-dev --no-scripts`; all 76 production packages and their licenses are inventoried. Node/npm/Composer/Git and development Python/PHP are build-machine tools only.
- All 57 Windows CPython 3.12 distributions have exact wheel URLs and SHA-256 pins in `wheels-win64.json` and `requirements-win64.lock`. Dependency requirements are evaluated with Windows/AMD64/CPython-3.12 markers and checked for missing or incompatible transitives before offline installation. ByteTrack also needs LAP outside Ultralytics' declared core dependencies; the previously absent `lap==0.5.13` is now the 57th locked distribution, and the readiness fixture executes two real tracking frames rather than prediction alone. `--no-deps` is not used as evidence of closure. No wheelhouse or pip installer is needed on the target.
- EasyOCR and Ultralytics require different OpenCV distributions. Their metadata records remain, but the assembler removes the conflicting `cv2` payload and installs/verifies the exact headless wheel's 82 files last. This explicitly selects one provider; it does not claim both overlapping payloads can coexist unchanged. The complete resulting bytes are covered by the content manifest.
- The source boundary now excludes only root Composer `vendor/`, retaining the tracked local HLS browser player and its Apache notice under `public/vendor/hls/`. An isolated staging test prevents this offline-video omission.
- Required models/configuration and a SHA-pinned recorded bus image are bundled. The plate-detector Hugging Face URL now names revision `9aaa5cd490abe0c165882ba87f4f62658ab54d01`. A checksum mismatch or missing required input stops assembly; no partial download is accepted.
- PHP uses its immutable archive URL, correcting the obsolete primary/fallback handling. VC++ uses an immutable Microsoft content URL, exact hash and version. Builder checks Microsoft Authenticode on Windows, including cache hits; target preflight checks it before execution. macOS cannot establish that Windows trust result.
- The license inventory includes Composer, Python, frontend/build dependencies and runtime/model inputs. Available npm notices are copied separately, and go2rtc's upstream MIT notice is independently pinned. The npm inventory includes build-only and optional platform packages; only built frontend assets and notices enter the installation. A license declaration/inventory is not redistribution approval.

| Component | Exact candidate decision |
| --- | --- |
| PHP | 8.4.26, NTS VS17 x64, required extensions/native DLLs retained |
| CPython | 3.12.10 embeddable AMD64, relative isolated `._pth` |
| Caddy / go2rtc | 2.11.7 / 1.9.14 |
| NSSM | 2.24-101-g897c7ad, win64 executable |
| VC++ x64 | 14.44.35211.0; SHA-256 `cc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713b` |
| Torch / Torchvision | 2.11.0+cpu / 0.26.0+cpu from the official CPU wheel index |
| ONNX Runtime / fast-plate-ocr | 1.31.0 / 1.1.0 |
| EasyOCR / Ultralytics | 1.7.2 / 8.4.41 |
| LAP (ByteTrack assignment) | 0.5.13, SHA-pinned cp312 Windows AMD64 wheel |
| NumPy / OpenCV | 2.4.3 / 4.13.0.92; headless `cv2` payload selected |
| Vite / Tailwind | 7.3.7 / 4.3.5; `@tailwindcss/vite` 4.3.5 |
| Axios / Laravel Vite plugin | 1.21.0 / 2.1.0 |
| Playwright | 1.65.0, build/test dependency; not shipped as a target prerequisite |
| Models | Existing SHA-pinned YOLOv8n, YOLOv8s, plate YOLO, CRAFT, English G2 and CCT-S-v2 ONNX/config |
| Execution | CPU candidate; packaged detector forces CPU, development auto-device behavior unchanged |
| Optional capabilities | Tesseract executable, standalone FFmpeg transcoding and GPU/CUDA unavailable in this candidate |

Complete exact package versions, hashes, supplier URLs and declared licenses are in the packaged `PYTHON-DEPENDENCIES.json`, `COMPOSER-DEPENDENCIES.json`, `RUNTIME-INPUTS.json`, `DEPENDENCY-LICENSES.json`, `DEPENDENCY-NOTICES.md` and retained notices. `BUILD-INFO.json` records source, architecture, tool versions, dependency-lock hashes, runtime pins, local-only mode and validation outcomes.

The real Ultralytics flags are `YOLO_OFFLINE=true` and `YOLO_AUTOINSTALL=false`, verified against its installed code. A packaged missing-model guard stops before YOLO can attempt a weights download; development behavior stays unchanged. The actual MJPEG listener reads `MJPEG_STREAM_BIND_HOST`, now explicitly `127.0.0.1` in the service environment.

Tesseract fallback cannot silently use a developer machine's PATH in packaged mode. EasyOCR/ONNX remain enabled. No standalone FFmpeg invocation is configured; existing OpenCV decoding uses its bundled codec DLLs and go2rtc uses its native supported paths. H264 RTSP/HTTP is the intended video baseline; H265-only/transcoded/browser-unsupported formats are not certified. The restricted Python service PATH retains bundled Python plus Windows System32 and built-in Windows PowerShell, which the existing reader adapter workaround actually invokes. It excludes arbitrary developer tools. These settings do not alter development-mode OCR/device selection.

## Preflight, services and preservation behavior

Target scripts use Windows PowerShell 5.1 syntax/APIs; the builder requires PowerShell 7. Native Windows 10+ AMD64 is required. ARM64/emulation and x86 server installations are rejected. The existing absolute ASCII/no-spaces local installation-root contract is retained, with `C:\PHILCST-VMS` as default.

Before copying program files or touching installation data, preflight checks elevation, native architecture, OS, local fixed disk, conservative free space, supported path lengths, hashes/unexpected payload files, VC++ publisher, reparse redirects, recognized existing root, database location/type, existing keys, service/task ownership, duplicate collectors and required TCP/UDP ports. A nonempty unrecognized root is refused. Non-SQLite, UNC/mapped-network database paths, an existing DB without its keys, and an existing environment without its database require explicit recovery rather than silent replacement. No unrelated process is killed to free a port.

D2 permits fresh installation and **same-source reinstall**. Legacy installations and different-source upgrades are explicitly refused before mutation; their safe conversion/update belongs to D5. Reinstall preserves database/accounts, APP_KEY/integration key, devices/calibration, media, historical records and setup state. A focused test also catches and fixes CRLF loss when replacing an unchanged `.env` value. Migrations/provisioning exist in target installation code but were not run against this working installation. Native PHP/OCR readiness runs before target database provisioning.

Fresh installation generates unique secrets and uses the existing protected, expiring first-admin delivery file and personal-password setup flow. No working database, default password, demo user or reusable setup code is in the payload. Reinstall does not automatically reopen completed setup. An interruption that leaves keys without a DB is deliberately refused for explicit recovery; this is not an automatic rollback facility.

The Inno bootstrapper stages its complete ZIP in TEMP, calls preflight before installation-root mutation, and invokes the same verified-file copy/install entry point as the ZIP workflow. The EXE workflow is prepared but uncompiled/unexecuted here. SHA sidecars require an independent trusted delivery channel. Detached RSA-SHA256 manifest signing and EXE Authenticode signing are implemented; no private key is embedded. Unsigned development checksums do not authenticate a publisher.

Services retain explicit bundled executables, working directories, automatic restart/backoff and NSSM 10 MiB log rotation. Laravel daily logs retain the configured 14-day policy; NSSM rotated archives still need an operational retention policy. PHP workers, Caddy, detector and live view use LocalService. Devices remains LocalSystem because its existing cross-subnet reader workaround adds/removes temporary adapter addresses. Code is read-only to LocalService; storage, local DB directory, caches and logs are writable; environment/setup delivery are restricted. Windows ACL/device-access behavior remains unverified natively.

Caddy/browser access, detector HTTP and go2rtc browser-media listeners are loopback-only in the candidate. No computer rename, network-profile change, LAN firewall opening or `.local` connection claim is made. Device LAN connectivity remains the existing camera/reader function, separate from browser/client exposure. Open/Status/Start/Stop entry points remain; browser closure does not control services. Status reports source/version, ownership/port problems and device freshness separately; readiness checks dependencies/models, SQLite and invoking-user writable state. A successful administrator write probe does not establish LocalService ACL acceptance.

Uninstall defaults to retaining database/accounts, media, keys and provisioning. It removes only owned known services/tasks and unchanged manifest-listed program files, keeping source/build metadata for same-source reinstall. It creates/removes no D2 LAN firewall rules. Native uninstall/reinstall acceptance is still required. Existing D5 backup/update/restore/outbox defects are not fixed or certified by D2.

## Checks actually executed

All application/browser tests used private source/vendor copies with isolated SQLite, storage, cache, camera and device paths. The build used external frozen snapshots and a private dependency cache. Recorded model inference used private configuration and networking blocked; it did not open cameras/readers.

| Check | Actual result |
| --- | --- |
| Full Laravel/PHPUnit | **815 passed; 8,676 assertions** on host PHP 8.5.3; includes accounts, printed tags, optional RFID, exact plates/eligibility, Review/evidence, gates/history, occupancy/lost UID, reports/media and deployment tests |
| Final installer-only rerun | **9 passed; 93 assertions**, after the final service-PATH/helper assertions |
| Package regression suite | **14 passed**: dirty/frozen sources, exclusions, extracted manifest/tamper/missing/extra files, ZIP traversal/case collision, Windows dependency closure, pins, local HLS staging, license inventory, CPU/Tesseract policies |
| PowerShell helpers / preflight | **17/17 + 13 passed** on macOS PowerShell 7; pure policies and hashes including hidden dependencies, not Windows APIs |
| PowerShell source parsing | **23 source scripts, zero failures**; generated staged copies were also parsed separately |
| JavaScript unit checks | **17 passed** |
| Python Windows-kit / snapshot fixtures | **4 + 5 passed**, private configuration, no hardware |
| Browser Registry status/owner | **83 checks passed**, isolated Chrome |
| Browser registration staff | **37 checks passed**, isolated Chrome |
| Browser optional RFID | **43 checks passed**, isolated Chrome |
| Frontend | Frozen `npm ci` and Vite production build succeeded |
| Host PHP readiness | Extensions, SQLite in-memory and real Argon2id hash/verify passed on PHP 8.5.3 / SQLite 3.53.4; not packaged Windows PHP |
| Host recorded-image CPU inference | Three YOLO model loads/predict calls, positive YOLO vehicle detections, two ByteTrack frames with stable vehicle IDs, EasyOCR read and ONNX recognizer run passed; macOS arm64 Python 3.14 native dependencies, not Windows binaries or plate-accuracy acceptance |
| Actual source guard | Current dirty release checkout refused before snapshot creation; no staging/commits |
| Artifact boundaries/integrity | Assembled known-data/secret/development-path scan, per-file hashes and complete ZIP extraction verification; exact final counts/hashes below |
| Actual final PowerShell payload verifier | All **28,092 files** passed the target helper on macOS PowerShell 7 with errors terminating; not Windows API/runtime acceptance |
| Repository checks | `git diff --check` passed; staged index unchanged; pre-existing registration changes preserved |

During implementation, an initial build exposed the missing frontend lock and another exposed non-string email-parser license metadata; both were fixed before successful assembly. The full-suite initial failures came from a Caddy template replacement and obsolete installer/LAN assertions; the template was fixed and assertions now verify local-only preflight/install-package behavior. Actual PowerShell payload inspection also exposed missing `-Force` on hidden-file attribute checks; the helper and hidden-file fixture now cover this case. A later inventory review added frontend notices and pinned go2rtc's notice before the final deliverable build. The actual tracking path also exposed the undeclared LAP requirement and mismatched upstream offline/bind variables; these are corrected and covered by tracking/service tests. The final source-boundary review also found that a broad vendor exclusion omitted the local HLS player; root-only Composer exclusion and a staging regression now preserve that player and notice.

The previously documented old `classification-ui.mjs` fixture mismatch remains outside this phase. It was not weakened or represented as passing. Selected browser checks above cover the actual preservation paths; no complete cross-platform browser acceptance is claimed. Source/package scans cover defined data locations and text secret/path patterns, not an exhaustive independent security audit.

## Native tests and release blockers

**NOT RUN:** Windows PHP/Python/native DLL execution, Microsoft offline Authenticode trust, Inno compilation/EXE execution, Windows PowerShell 5.1 runtime behavior, native CPU feature compatibility, LocalService ACLs, NSSM/services/tasks/ports, clean offline Windows 10/11 installation, packaged first-admin/login, synthetic packaged RFID, missing/corrupt DLL/model native failures, failure injection, same-source reinstall/uninstall preservation, reboot/browser-closed operation, physical cameras/readers, USB drivers/privacy, codecs/video and LAN/client HTTPS.

`build/windows/ci/NATIVE-ACCEPTANCE.md` provides the offline clean-VM procedure for those checks, including expected refusals and retained-data reinstall. The guarded `install-check.ps1 -ConfirmIsolatedRunner` automates the core native setup/login/readiness/reinstall/removal smoke path only. A hosted CI runner with development tools/internet is not clean offline desktop acceptance. The workflow was prepared, not triggered by this task.

Remaining release gates:

1. Review/approve and explicitly commit the intended source; current output is a dirty-working-tree development snapshot. Do not stage or discard existing work automatically.
2. Obtain Windows signing credentials and an independently approved publisher identity. Produce/sign the EXE and release manifest and verify actual signatures offline. Current ZIP is unsigned and not publisher-authenticated.
3. Institutional redistribution review, including Ultralytics/YOLO/recorded fixture AGPL obligations, third-party model provenance/rights, codec/native-library notices and Microsoft redistributable conditions. Metadata and available notices do not establish institutional approval.
4. Execute the complete native acceptance matrix on actual artifact hashes, including clean Windows 10/11 and the intended Intel/AMD CPU baseline. The CPU wheel selection is implemented; Windows CPU compatibility is unverified.
5. Complete D4 named HTTPS/client isolation and D5 coherent backup/update/restore/transfer and durable delivery. Existing writer-fencing, partial recovery, denylist/history/context and volatile outbox findings remain release gates.

## Windows installation guidance

Do not apply this D2 candidate to the existing working Windows installation. Legacy/different-source installs are refused; leave their database, accounts, secrets, devices and media in place for D5. For D2 acceptance, transfer the complete verified ZIP to a separate disposable Windows x64 VM with internet disconnected and no development tools. Verify its checksum independently before extracting or running a supplied helper.

```powershell
Expand-Archive -LiteralPath .\PHILCST-VMS-VERSION-win64.zip -DestinationPath .\candidate
$payload = (Resolve-Path .\candidate\PHILCST-VMS).Path
# Elevated Windows PowerShell; use an isolated local test root.
& "$payload\app\deploy\windows\install-package.ps1" -Payload $payload -Root C:\PHILCST-D2-Test -AllowDevelopmentCandidate
& C:\PHILCST-D2-Test\app\deploy\windows\readiness.ps1 -VerifyContent -Inference -Json
& C:\PHILCST-D2-Test\app\deploy\windows\status.ps1 -Json
```

The target uses only bundled executables and built-in Windows PowerShell. Deliver the protected local setup code privately, choose a personal password, and delete the delivery file. Open the local loopback URL/shortcut; do not advertise LAN access. In an isolated VM only, the real synthetic RFID endpoint is `POST /api/v1/integration/rfid-scans` with `X-Api-Key` and `tag_uid`, `scan_location`, `reader_name` (and the existing optional evidence/time fields); use a synthetic UID created through that VM's inventory. The ordinary browser Test Scan remains preview-only. Never execute that acceptance post against a working installation. Follow `OFFLINE-INSTALL.md` and record each native result against this exact source and ZIP hash.

Build command used: `pwsh -NoProfile -File build/windows/build-release.ps1 -Version 0.1.0-d2.1 -DevelopmentSnapshot -Python python3 -OutDir build/windows/dist/D2-Windows-x64 -CacheDir <private-build-cache>`. The build machine used PowerShell 7.5.4, Python 3.14.7/pip, PHP 8.5.3, Composer 2.9.5 and Node 22.18.0. On a Windows builder, add `-Installer` with Inno Setup available to prepare the native EXE. Release mode additionally requires clean explicit `-SourceCommit`, `-RedistributionApproved` and `-SigningCertificateThumbprint`; it cannot skip native runtime checks.

## Built artifact and exact hashes

- Version: `0.1.0-d2.1-dev.092593dd1048` — development, unsigned, local-only.
- Base commit: `d0e0d93d2a7193b956735c5c58a234b945d1a3f1`.
- Canonical source SHA-256: `092593dd104894ad8d0236c292b3129eb3590e8998484248d47f11674041a4d5`; **705 captured source files**. All captured hashes still matched the working inputs at final audit.
- ZIP: `build/windows/dist/D2-Windows-x64/PHILCST-VMS-0.1.0-d2.1-dev.092593dd1048-win64.zip`.
- ZIP bytes: **576,413,086** (549.7 MiB).
- ZIP SHA-256: **`4153ad831042640144f8909177de4bf0fd5dcafcb22b608121b42e67850552dc`**.
- Checksum sidecar: same ZIP path plus `.sha256`.
- Expanded stage: `build/windows/dist/D2-Windows-x64/PHILCST-VMS/`.
- Manifest: **28,092 files**, **1,474,807,228 uncompressed file bytes**, including application/runtime/model/configuration/browser assets and notices.
- `CONTENT-MANIFEST.json` SHA-256: `3c0f892c89a55598af3009aa2601ac05019ab15084736ee0e0ba617a107929fc`.
- `BUILD-INFO.json` SHA-256: `f9ee1258a0ccabf21471274913f1c86530185b98078a506d44db940d2896637d`.
- `SOURCE-INFO.json` SHA-256: `f1d8fa74c4fe2dce1c3d9fc56922c5b455708537e10519abc8dcce9ff2be5151`.

The actual ZIP was fully extracted, every listed file rehashed, extra/missing files checked and the temporary extraction removed only after verification. The final staged payload also passed the PowerShell verifier. Its local HLS player is byte-identical to the tracked source and retains its license. The payload has no installed `.env`, working SQLite DB or setup delivery file. Inventory counts: 76 production Composer packages, 57 Windows Python distributions, 160 frontend/build lock entries and one existing vendored HLS player.

`BUILD-INFO.json` correctly records packaged Windows runtime execution, CPU inference and Microsoft signature validation as **NOT RUN** on this macOS builder. Host CPU inference/tracking is reported separately above. No Setup EXE or publisher signature was produced. Source/ZIP hashes identify this candidate; they are not a release approval or publisher identity. All generated payloads are Git-ignored. Superseded task-generated candidates were removed; dependency cache and logs remain private.

### Exact lock hashes

- composer: `2afca3b7694533c022966e894bbc401b3e564e8b053bd4df24cbf15b383bb3e1`
- frontend: `00c36a25a97417309df04f92f312b65fa9de88d5e2cbe556c1a8009693b6a3c9`
- python: `3f4f26addce053eec71654645e5915e042fda9508ccf7afe125bffaf63c98e84`
- wheel_inputs: `36dcf4faff03f65f3fa6896847b6e2dc17e7451957d8969fbe50b31f834604cc`

## Complete D2 changed-file list

These paths are compared with the recorded D2 starting workspace, not attributed from the entire already-dirty Git diff. Existing registration edits and D1 reports are excluded from the D2 list. **No migrations were added or changed.** Only private test database migrations ran.

Modified implementation/test/documentation files:

```text
.github/workflows/windows-kit.yml
app/Services/Go2rtcService.php
build/windows/README.md
build/windows/build-release.ps1
build/windows/ci/install-check.ps1
build/windows/installer/PHILCST-VMS.iss
build/windows/installer/stop-old.ps1
build/windows/runtimes.json
build/windows/templates/Caddyfile.template
build/windows/templates/env.template
config/monitoring.php
deploy/windows/install.ps1
deploy/windows/lib/common.ps1
deploy/windows/status.ps1
deploy/windows/uninstall.ps1
school-vehicle-monitoring-detector/anpr.py
school-vehicle-monitoring-detector/detector_service.py
tests/Feature/AccountReleaseBoundaryTest.php
tests/Feature/LiveViewWebrtcTest.php
tests/Feature/WindowsInstallerTest.php
tests/windows/common.tests.ps1
```

New source files:

```text
DEPLOYMENT-D2.md
build/windows/OFFLINE-INSTALL.md
build/windows/assemble.ps1
build/windows/checks/inference.py
build/windows/checks/php-readiness.php
build/windows/ci/NATIVE-ACCEPTANCE.md
build/windows/fetch-wheels.py
build/windows/license-inventory.py
build/windows/opencv-provider.py
build/windows/package.py
build/windows/requirements-win64.lock
build/windows/wheels-win64.json
deploy/windows/install-package.ps1
deploy/windows/lib/preflight.ps1
deploy/windows/preflight.ps1
deploy/windows/readiness.ps1
package-lock.json
tests/deployment/test_package.py
tests/windows/preflight.tests.ps1
```

Existing Windows files normalized to CRLF only; no backup/update/restore/startup behavior was changed in these paths:

```text
deploy/windows/backup-now.ps1
deploy/windows/collect-logs.ps1
deploy/windows/gate-kiosk-shortcut.ps1
deploy/windows/lib/daily-backup.cmd
deploy/windows/lib/scheduler.cmd
deploy/windows/restart-all.ps1
deploy/windows/restore.ps1
deploy/windows/start-all.ps1
deploy/windows/stop-all.ps1
deploy/windows/update.ps1
tools/start/install-windows-autostart.ps1
tools/start/start-system.bat
```

Generated ZIP, checksum and 28,092 payload files are listed by the artifact/content manifest above; they are not staged project source.

## Reproduction evidence and handoff

The private evidence pointer is `/tmp/philcst-deployment-d2-workspace.txt`. It identifies build logs, `laravel-final.txt`, `laravel-installer-latest.txt`, browser logs, JavaScript/Python logs, `host-inference-tracking.log`, `powershell-delivery-payload.txt`, preservation audit and build dependency cache. Full-suite JUnit: `/tmp/philcst-d2-laravel-final.xml`. These local logs/cache directories are not release inputs or production backups.

Focused source checks: `python3 -B -m unittest discover -s tests/deployment -v`; `pwsh -NoProfile -File tests/windows/common.tests.ps1`; `pwsh -NoProfile -File tests/windows/preflight.tests.ps1`. Laravel ran as `php vendor/bin/phpunit` in the isolated copy; the final installer filter ran after the service-environment changes. Selected Chrome runners and `npm run test:js` also ran there. Never substitute the working installation DB/device configuration for those fixture paths.

| Next phase | Required handoff |
| --- | --- |
| D3 | Implement native Intel/Apple Silicon packages separately. Resolve Intel dependency/minimum-OS issues; include explicit ByteTrack/LAP and real tracking readiness. Reuse exact-source/boundary ideas and preserve local HLS assets. Do not reuse Windows wheels or assume the host inference proves Intel/macOS-minimum compatibility. |
| D4 | Implement named HTTPS and client trust/firewall/media access. Preserve loopback internal APIs; distinguish `MJPEG_STREAM_BIND_HOST` from exported stream-host settings, and use the go2rtc `webrtc_host` hook deliberately. No `.local`/rename workaround is delivered by D2. |
| D5 | Provide tested maintenance/writer/task fences, consistent DB/media/keys backup and atomic recovery/update/transfer, permanent lost-UID handling, review/audit relationships, original occurrence context and durable acknowledged collector outbox. Legacy/non-SQLite conversion is a separate approved workflow. Current scripts remain uncertified. |
| D6 | Rebuild all three final native packages from one reviewed clean commit; complete OS/CPU/offline/reboot/hardware/browser/lifecycle acceptance, signing/notarization, redistribution notices and operational log retention. Replace obsolete legacy project LAN/recovery instructions; the D2 top-level OFFLINE-INSTALL guide governs this candidate. No push/publication without separate authorization. |

**D2 stops here. Wait for “Go Deployment D3.”**
