# Native installed acceptance — exact final artifacts only

Use disposable targets with no working installation/data/devices. Independently
verify the complete artifact/hash/signing identity before executing any helper.
Record exact source/version/build, native architecture, OS version, hardware,
command and evidence for each case. The local acceptance ledger starts NOT RUN or
BLOCKED. No hosted runner, adapter or file-header inspection changes that status.

## Target matrix

- Windows 10 and a current supported Windows version, native x64 Intel/AMD with
  representative supported CPU/reader/camera and built-in PowerShell5.1.
- Native Intel Mac at the artifact's declared minimum/current OS; production use
  additionally blocked by its historical PHP/Torch security/maintenance decision.
- Native Apple Silicon at the final declared minimum/current OS. New arm64 PHP
  closure imposes macOS15; execution on macOS26 does not certify15 or14.
- Windows Edge/Chrome/Firefox and Mac Safari/Chrome/Firefox browser clients with
  native OS/Firefox trust integration and at least two simultaneous real clients.

## Required cases and evidence

| Ledger case | Procedure/evidence |
| --- | --- |
| clean_offline_install | Start clean with empty caches and no PHP/Python/Homebrew/XAMPP/Composer/Node/Git; unplug WAN, transfer only verified package, install through OPERATOR-GUIDE, retain installer logs and fresh lifecycle receipts |
| personal_setup_login | Privately deliver installer code, set personal password in canonical HTTPS setup, login, verify no demo account and repeat setup stays closed; redact all credentials |
| browser_closed_monitoring | Close browsers, pass actual hardware traffic and prove services/occurrence reconciliation continue |
| reboot_logged_out | Reboot/logout, check owned services, permissions/privacy and one collector before/after browser login; account for FileVault/device network timing |
| canonical_browser_trust | Enroll only independently pinned public root, verify no warning bypass, canonical Host, secure cookies/private images/signaling, wrong root/name refusal and renewal with retained issuer |
| two_lan_clients_and_isolation | Configure explicit stable NIC/private address; two real clients operate simultaneously, test local↔LAN change, raw-port probes/IPv6/conflicts, verify only owned mappings/rules change |
| physical_camera_ocr_rfid | Supported camera/reader, native codecs/drivers, exact plates/Review, real RFID UID/leading zeros/lost permanence; include unknown/unreadable observations without fabricated events |
| registration_review_reports_images | Untagged registration, exact order/O0/I1/zeros, owner validation, Guest historical classification, same-occurrence Review corrections, both image kinds, filters/all-page Print/CSV/PDF image output |
| durable_delivery_retries | Interrupt integration, retain media/outbox, retry/lost ACK, compare exact event/receipt/occupancy IDs; never clear spool to claim success |
| backup_restore_fault_recovery | Coherent DB/media/keys backup on protected encrypted media; exact same-source restore and monotonic lost/security/trust state, sessions invalidated, no source identity bypass |
| same_source_reentry | Repeat same source/version/build package; prove no key/account/history/code issuance changes and verification-only result; changed source refused |
| data_preserving_uninstall | Currently BLOCKED. Inno/managed remover must refuse before any deletion. Native removal/retention/re-enrollment/reinstall semantics require a separately implemented/certified path |
| exact_cross_version_update_rollback | BLOCKED until a real reviewed exact origin/schema/runtime pair and authenticated publisher roots exist; then verify signed online/local import, local admin confirmation, migrations on copies, locked self-update, rollback/new traffic and interrupted promotion |
| two_host_transfer_retirement | BLOCKED until a real exact pair is reviewed; native distinct hosts, independent authorities, protected media, signed target challenge, coherent source retirement, verified isolation, capsule identity handoff, pending replay and client reconnect |
| disk_permissions_locked_files | Disk-full/denied ACL/missing keys/locked executables at stage/promote/start boundaries, no silent restart, recover with retained verified controller; record failures as failures |
| loaded_video_revocation | Real simultaneous camera playback/fallback/reconnection under load; logout/disable/session revocation, server lease bound plus buffers; synthetic timers alone are insufficient |

Run Open/Status/Start/Stop using installed shortcuts; Stop must persist maintenance
and a retired source must not restart through ordinary controls. Preserve the
canonical HTTPS issuer, APP_KEY, all private state and safety material. Do not
modify school DNS/router automatically or replace missing keys. Disaster activation
requires independently held authority/material, newer security escrow, loss policy
and old-server isolation; keep blocked when those prerequisites are unavailable.

## Reporting results

Keep native evidence outside immutable artifacts and exclude credentials/private
keys. On the review/build machine use `build/delivery/record.py` with the current
ledger hash, exact platform/test/status, actual evidence file, command, OS and scope.
Runtime/fixture case names must be separate from native installed cases. Required
hardware cases need physical hardware evidence. Archive mutations invalidate old
records and require new hashes/affected tests. Transfer evidence is sensitive and
must have private encrypted-volume custody, not a public artifact upload.
