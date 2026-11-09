# Native D2 acceptance — NOT established by source/PowerShell parsing

Run only in disposable, snapshotted Windows 10 22H2 x64 and Windows 11 x64 VMs
with no working installation, devices or real accounts. Use a separate physical
Intel/AMD machine for the final device check. Record exact ZIP/EXE SHA-256,
SOURCE-INFO source hash, OS build, architecture, CPU features and result for each
case. A hosted Windows runner with internet/build tools is only a smoke test.

1. Verify checksums/publisher through an independent trusted channel. Take a VM
   snapshot; disconnect internet, clear package caches and ensure PHP, Python,
   Node, npm, Composer and Git are absent from PATH. Keep only built-in Windows
   PowerShell. Transfer the complete candidate by USB/virtual disk.
2. Fresh install into `C:\PHILCST-D2-Acceptance`. An unsigned development candidate
   requires the explicit development flag and remains unapproved. Confirm no
   downloads, no Windows computer rename, no LAN firewall openings, and loopback
   browser/media listeners. Test the complete ZIP and Inno EXE separately.
3. Run installed `readiness.ps1 -VerifyContent -Inference -Json`: PHP extensions,
   SQLite, Argon2id, CPU-only Torch/Torchvision, ORT/fast-plate-ocr, all model loads
   and the recorded inference/tracking fixture must pass without network. No camera/reader required.
4. Deliver the protected first-admin code privately; activate with a fresh personal
   synthetic password, sign in, remove the delivery file and create synthetic
   Registry/Guest records. Untagged registration must still work. Supply a printed
   number for RFID. Read the synthetic integration key locally (never log it) and
   post one fake RFID read to the loopback integration endpoint. Verify its saved
   result and retry semantics without physical RF equipment.
5. Reinstall SAME-SOURCE; compare keys/config hashes, account login, DB record IDs,
   tag number/UID, media and completed setup. Confirm no new setup code or reseed.
   A different-source or legacy upgrade must refuse before mutation (D5 pending).
6. With services stopped, rename one required DLL/model and run readiness: it must
   fail with a useful missing/corrupt message and no download. Restore the file.
   Tamper one extracted byte before install: preflight must refuse before DB/key
   creation. Test an unexpected payload file and invalid publisher fingerprint.
7. Test occupied PHP/stream ports, a same-named service owned by a different root,
   an unrelated listener, the old development startup task, a duplicate collector,
   insufficient disk, network-mapped root/database, non-SQLite configuration and
   missing existing keys. Confirm no unrelated process/task/service is stopped.
   Test unsupported/overlong paths and ARM64/x86 server rejection where available.
8. Inject file-copy failure and native readiness failure before provisioning.
   Installation must fail clearly, retain existing data and not report success.
   Document any stopped owned services. D2 does not certify database rollback.
9. Reboot with the browser closed. Check all owned services, working directories,
   retries/log rotation and local login. Check LocalService file ACLs and that the
   Devices LocalSystem exception is needed only for the adapter workaround. Check
   the actual school camera/reader separately, including OS privacy/driver grants.
10. Uninstall with default **retain data**, confirm only owned services/tasks and
    program files removed and accounts/keys/DB/media remain. Reinstall the same
    source and sign in. Test explicit data removal in a fresh disposable snapshot.
    Unrelated service/task/firewall entries must remain untouched.

The guarded `install-check.ps1 -ConfirmIsolatedRunner -Setup ...` automates the
basic installer/setup/login/readiness/reinstall/removal smoke path. It does not
prove offline cleanliness, Windows 10/11 desktop behavior, reboot or physical
hardware. Complete and sign the manual record above before release approval.
D4 named HTTPS/client setup and D5 recovery/outbox acceptance remain release gates.
