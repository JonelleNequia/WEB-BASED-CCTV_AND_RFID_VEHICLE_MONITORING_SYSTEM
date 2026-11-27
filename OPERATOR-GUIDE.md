# PHILCST Vehicle Monitoring — packaged operation

Current preparation: Deployment D6, 27 November 2026 (Asia/Manila). Read
[the testing release details](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/tag/v0.1.0-d6.4-dev.ecc499bd0d7a) for actual artifacts and acceptance.
These are development acceptance candidates unless independently approved and
signed. No production/native installation certification follows from a checksum.
Testing release: https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/tag/v0.1.0-d6.4-dev.ecc499bd0d7a.
Use its exact named assets and SHA256SUMS; automatic Source code ZIPs are not installers.

## Choose the main server or a browser client

One main server owns local SQLite, private media, device connections and durable
occurrence reconciliation. Additional computers are browser clients; never share
SQLite over SMB/NFS, clone collectors or run two active main servers.

| Role | Package | Minimum versus tested support |
| --- | --- | --- |
| Windows main server | Complete native x64 ZIP; EXE only when built by native Inno | Windows 10+ x64 Intel/AMD target; actual Windows native acceptance remains required |
| Intel Mac main server | Complete `macos-x86_64` archive | Dependency-derived minimum recorded in BUILD-INFO; Intel runtime/install acceptance unverified; historical PHP/Torch security/maintenance blocks production |
| Apple Silicon main server | Complete `macos-arm64` archive | PHP 8.4.28 uses native Sequoia bottles; final Mach-O audit determines minimum; newer-host execution is not minimum-OS certification |
| Windows or Mac browser client | Small Connect/Reconnect ZIP | Native OS trust/enrollment acceptance required; no server runtimes/database included |

Choose artifacts by architecture, exact version, source SHA-256 and common build
identity in `LOCAL-UPLOAD-INVENTORY.json`. Windows ARM/emulation and Rosetta server
installation are unsupported. Older D2/D3 archives omit later phases and cannot
be relabeled current. A source-review ZIP is not an installer.

The target needs no PHP/Python/Homebrew/XAMPP/Composer/Node/Git or internet package
installation. Physical camera/reader drivers, NIC configuration and printer drivers
are separate prerequisites. Tesseract executable, external FFmpeg transcoding,
GPU inference and arbitrary camera codec support are not supplied promises.

## Verify before running anything

Privately obtain an independently approved artifact SHA-256 and, for a release,
its publisher signing identity. Compare full archive size/hash before extraction.
A checksum downloaded with an archive establishes integrity only. Do not run an
untrusted archive's verifier to establish that archive's own publisher identity.
Check Windows Authenticode against the approved certificate; Mac Developer ID,
notarization/stapling and offline Gatekeeper acceptance remain release gates.
Ad-hoc signing is not Developer ID. Never disable Gatekeeper, TLS or signature
checks to make a candidate pass. Development switches are for disposable isolated
acceptance targets, not authorization to overwrite a working installation.

Exact current local filenames/hashes and missing platforms are in the D6 report
and generated inventory. No guessed download URL or nonexistent Setup EXE is an
installation prerequisite. Transfer the complete verified archive on protected USB
or another institutionally approved channel, then extract outside the target root.

## Windows main server — acceptance procedure

When a complete Windows ZIP has actually been built, verify it using
`Get-FileHash -Algorithm SHA256`, extract it, then open built-in Windows PowerShell
**as Administrator** in the extracted `PHILCST-VMS` directory:

```powershell
& .\app\deploy\windows\install-package.ps1 -Payload $PWD.Path -Root C:\PHILCST-VMS -AllowDevelopmentCandidate
```

The candidate switch is for isolated unsigned acceptance. A signed release instead
requires its independently approved publisher thumbprint. Native x64, local fixed
ACL-capable disk, disk space, complete hashes, ownership and ports are preflighted.
Do not put a package over XAMPP/development/legacy data; adoption is refused.

Fresh setup creates private installation-specific encryption/integration secrets,
a local DB and non-account defaults. D4 creates a persistent local HTTPS issuer.
D5A initializes durable delivery; the explicit D6 finalizer quiesces owned services,
registers D5B host/security state, retains a D5D-capable independent controller,
creates protected ownership and an installed compatibility status, then resumes
through readiness. No publisher/peer key is trusted automatically. Scheduled backup
remains disabled pending native acceptance/custody configuration.

Use installed Open/Status/Start/Stop shortcuts. Stop persists owned maintenance;
Start resumes that exact operation subject to recovery/ownership checks. A retired
server cannot be restarted through these shortcuts. A failed restore/update needs
its matching recovery controller, not a forced generic Start.

## Intel Mac main server — acceptance procedure

Use only the native `macos-x86_64` complete archive and a disposable Intel target.
The current Intel dependency/maintenance blocker prevents production approval.
After externally verifying and extracting the archive:

```sh
./Install.command --allow-development
```

The shell checks native architecture and minimum OS before any bundled interpreter
or Rosetta prompt. Default root is `/Library/Application Support/PHILCST-VMS`.
No Homebrew installation is needed on the target. Use `Controls/Open.command`,
`Status.command`, `Start.command`, `Stop.command`. Native launchd, service-user,
Keychain/firewall, minimum OS, reboot and hardware acceptance remain NOT RUN until
performed on the actual target; Mac layout tests on another OS do not qualify.

## Apple Silicon main server — acceptance procedure

Use the `macos-arm64` complete archive and the same verified `Install.command`
procedure above. The native library audit and BUILD-INFO identify its actual
minimum OS and tested host. The refreshed PHP supplier closure may impose a higher
minimum than older D3 packages. Do not infer macOS 14 support from an old report.
Services and private mutable data are separate from root-owned release code.
Fresh D6 lifecycle finalization is identical in purpose to Windows.

## Personal first-admin setup and later accounts

There is no shipped admin password or demo account. The authorized installer
privately delivers the expiring code from the protected setup file:

- Windows: `C:\PHILCST-VMS\config\setup\first-admin.txt`.
- Mac: `ROOT/shared/setup/first-admin.txt` (root-only).

After explicit certificate enrollment, the owner opens
`https://philcst.vms.internal/setup`, sets a personal password and signs in.
Delete the plaintext delivery file after private handover; never put codes in a
URL, general log or screenshot. Completed setup is never reopened on re-entry.
Admins create individual admin/guard accounts through Accounts and privately
deliver their one-time activation codes. See [account security](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/ACCOUNT-SECURITY.md).
Do not replace APP_KEY or fabricate temporary credentials to repair access.

## Canonical name, local/LAN mode and browser clients

Canonical origin is always **https://philcst.vms.internal**. Fresh packages default
to this computer only; the main server maps the name to loopback. Public browser
access uses Caddy HTTPS443. Collector integration/FastCGI/control remain loopback.
Sharing is an explicit D4 administrator action with a stable reserved private IPv4,
real NIC and allowed scope. The installer does not change router/DNS, assign an IP,
rename the computer, disable firewalls or use public ACME.

Read [network setup](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/network/NETWORK-SETUP.md) for installed bundled-Python
commands to inspect/configure mode, independently verify the installation root
fingerprint, explicitly enroll server trust and export **only the public root**.
Preserve the private issuer and root receipt; missing keys require recovery,
never silent CA regeneration. Software publisher, TLS issuer and transfer authority
are separate trust systems.

Clients need only a browser and their small Connect bundle. Follow
[CLIENT-CONNECTION.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/clients/CLIENT-CONNECTION.md). Independently deliver
public root/fingerprint and verify the selected server address before adding trust
or owned mappings. Windows uses elevated Connect-to-PHILCST.ps1; Macs use the sudo
Connect-to-PHILCST.command. DNS is administrator-managed; helpers update only owned
hosts entries, detect conflicts and preserve unrelated trust/mappings. Firefox's
trust policy needs its documented native procedure. Never bypass certificate errors.

After an authenticated server transfer, use Reconnect with the old recorded address,
new independently verified address/root and explicit old-server isolation. Issuer
rotation requires separate verified root enrollment. Sign in again and refresh
assets deliberately; do not run reload loops. Clients never run collectors.

## Recovery, updates and transfer availability

| Path | Current contract |
| --- | --- |
| Fresh installation | Implemented including D4–D5 prerequisites; actual native installed acceptance is recorded separately |
| Exact same-source/version/build re-entry | Read-only installed/capsule/ownership verification; no copy, seed, migration or setup-code issuance |
| Legacy/older installation adoption | Refused; requires a separately reviewed exact adoption path |
| Same-installation restore | D5B controller, coherent DB/media/keys set and monotonic security reconciliation; exact source/host only |
| Cross-version same-host update | D5C signed metadata + installed trust + exact certified path + concrete local OS-admin confirmation; production allowlist currently empty |
| Cross-host/platform transfer | D5D challenge/independent authorities + explicit exact pair + retirement + verified source isolation; production allowlist currently empty |
| Disaster activation | BLOCKED pending independently held authority/material, latest security escrow, explicit recovery-loss policy and isolation evidence |
| Managed data-preserving uninstall | BLOCKED pending native removal/retention acceptance; Inno refuses before removing anything; do not delete keys/state or run legacy removal scripts |

D6 does not authorize broad fixture identities as production compatibility.
Keep safety sets, independent controllers and source installation until acceptance
and an explicit retention decision. A failed target after new traffic needs another
coherent handoff, not blind source rollback. Local locks/epochs are not remote
leases and cannot protect against arbitrary administrator machine-snapshot restore.

Use [MAINTENANCE.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/lifecycle/MAINTENANCE.md),
[RECOVERY.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/lifecycle/RECOVERY.md), [UPDATES.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/updates/UPDATES.md)
and [TRANSFER.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/deploy/transfer/TRANSFER.md) with the trusted installed controller.
Backup/transfer archives contain private secrets and are **not encrypted by these
tools**. Use approved encrypted volumes with separately held recovery material and
private ACLs. Never put safety copies in a public/shared unencrypted folder.

Update check/download defaults are off, automatic install is absent. Without
independently provisioned publisher roots, compatibility status is visible but
preparation is disabled. Absence of internet/discovery does not interrupt monitoring.
Enrolling publisher trust or a transfer peer requires independently verified material;
no fixture key is a substitute. Browser requests cannot install software by themselves.

## Interrupted fresh finalization and troubleshooting

The new finalizer records `state|shared/provision/fresh.json` and exact operation,
source, host, identity and secret fingerprints. It can resume only that recorded
fresh intent; it cannot adopt an old DB or recreate missing completed ownership.
Before finalizer intent exists, an interrupted native installer still requires
explicit recovery review. Never reset/reseed an interrupted installation.

After inspecting a dead process, use the trusted installed `fresh_setup.py`
`recover-owner` and the matching D5A operation-lock recovery, then `finish` with the
same exact source confirmation. Keep service admission fenced until all proofs pass.
Use the retained D5D-capable controller when available; never run incoming recovery
scripts or change APP_KEY. Preserve setup delivery and completed-account state.

Operator logs: Windows `ROOT/logs`, `ROOT/config/install-result.txt` and protected
state journals; Mac `ROOT/shared/logs`, root-private web logs and provision/recovery/
update/transfer journals. Status separates application readiness from device readiness.
For a failure report include version/source/build identity, native OS/architecture,
failed operation UUID and safe error summary; exclude passwords, codes and private keys.
Check disk, permissions, occupied ports, stable NIC/address, clock/certificate expiry
and owned service status. Missing dependency/hash errors require the full trusted
package, not pip/npm/Composer on the target.

The main server must remain awake and powered for monitoring. Closing a browser does
not stop services; sleep/shutdown does. No software can recover uncaptured traffic
while a camera/server is off. Loaded live video, real RFID/USB/codec behavior and
physical Print/PDF output require native hardware acceptance.

## Public testing-release licensing

Project source is AGPL-3.0; third-party material retains its own licenses. Obtain
LICENSE, PROJECT-LICENSING.md, THIRD-PARTY-NOTICES.zip and the matching frozen-source
archive from the same testing release. No signing/native acceptance or production
compatibility approval follows from public download availability.
