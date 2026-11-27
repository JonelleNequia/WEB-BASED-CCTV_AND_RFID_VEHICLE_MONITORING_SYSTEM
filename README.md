# PHILCST Vehicle Monitoring

Offline-first Laravel/Python vehicle monitoring with one main server and optional
Windows/Mac browser clients. Registered vehicles can use exact eligible plate
identification without RFID. Evidence, Review, occurrence history and campus state
remain separate from registration and device inventory.

## Download — Testing Release

**Offline testing candidates — not certified production installers.** Download the
complete package for the main server. Other PCs need only a browser helper.

| Use | Platform | Direct download |
| --- | --- | --- |
| Main server — complete installation | Windows x64 | [Download for Windows x64](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/PHILCST-VMS-0.1.0-d6.4-dev.ecc499bd0d7a-win64.zip) |
| Main server — complete installation | Intel Mac | [Download for Intel Mac](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/PHILCST-VMS-0.1.0-d6.4-dev.ecc499bd0d7a-macos-x86_64.tar.gz) |
| Main server — complete installation | Apple Silicon Mac | [Download for Apple Silicon Mac](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/PHILCST-VMS-0.1.0-d6.4-dev.ecc499bd0d7a-macos-arm64.tar.gz) |
| Additional PC — browser connection helper | Connect another Windows PC | [Connect another Windows PC](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/Connect-to-PHILCST-Windows-0.1.0-d6.4-dev.ecc499bd0d7a.zip) |
| Additional PC — browser connection helper | Connect another Mac | [Connect another Mac](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/Connect-to-PHILCST-Mac-0.1.0-d6.4-dev.ecc499bd0d7a.zip) |

[Installation guide](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/OPERATOR-GUIDE.md) ·
[SHA-256 checksums](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/download/v0.1.0-d6.4-dev.ecc499bd0d7a/SHA256SUMS) ·
[Release details](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/releases/tag/v0.1.0-d6.4-dev.ecc499bd0d7a)

Windows includes a complete ZIP, **no Setup EXE**. Macs use `Install.command`;
Apple Silicon requires macOS15+, Intel macOS14+. Minimum-OS/native installation
acceptance is pending. Packages are unsigned/ad-hoc; Windows/Intel runtime testing,
production update/transfer approvals and maintenance gates remain outstanding.
GitHub's automatic **Source code** downloads are not complete offline installers.

Detailed platform setup, local/LAN access, personal first-admin activation and
backup custody are in the installation guide. Installed operation stays offline;
no PHP/Python/Homebrew/XAMPP/Composer/Node/Git is needed on the target. Use
**https://philcst.vms.internal**. Never overwrite a working/legacy installation
with these acceptance candidates.

## Application behavior

Registry status and campus state are independent. RFID is optional; tag display
numbers preserve leading zeros and differ from reader UID. Lost UIDs remain
unavailable. Exact plate normalization preserves character order, zeros and O/0,
I/1 distinctions. Historical Guest occurrences remain historical after later
registration. Registered occurrences remain in Activity Logs and are excluded from
Guests. Uncertain identifier/OCR evidence remains in Review with its original media.

Settings uses Gates/Advanced. At least two non-archived gates remain; inactive
gates count. Archiving preserves stable IDs, history and devices while releasing
operational assignments. Activity Logs and Registry/Guests reporting share server
filters; occurrence snapshots and plate crops retain exact event ownership.

Current implementation reports:
[Registry 1](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE1.md), [2](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE2.md),
[3](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE3.md), [4](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE4.md), [5](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE5.md),
[6](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE6.md), [7A](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE7A.md), [7B](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE7B.md),
[7C](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE7C.md), [7D](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE7D.md), [7E](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGISTRY-PHASE7E.md),
[literal plate reconciliation](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/REGRESSION-LITERAL-PLATE-RECONCILIATION.md),
[OCR readability](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/OCR-READABILITY-FIX.md),
[automatic classification](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/OCR-AUTOMATIC-CLASSIFICATION.md),
[optional RFID/anomaly investigation](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/OPTIONAL-RFID-ANOMALY-FIX.md).
These reports record their own historical verification; they are not installation
instructions or permission to migrate working data.

## License

Project source is licensed under [AGPL-3.0](LICENSE); third-party material retains
its own notices. The matching sanitized frozen source is available with the testing
release.

## Lifecycle and development

Use the [operator guide](OPERATOR-GUIDE.md) for current install/Start/Stop, backup
custody, update/transfer blocks, client enrollment and troubleshooting. Earlier
D1–D5 reports are evidence/handoffs, not competing live-install recipes.

[DEVELOPMENT.md](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/DEVELOPMENT.md) is the isolated development/test path.
[Browser fixtures](https://github.com/JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM/blob/v0.1.0-d6.4-dev.ecc499bd0d7a/frozen/tests/browser/README.md) use private synthetic DBs/devices.
Never run test seeds, reset commands, development autostart or two collectors
against an installed server. Builders and target installers are separate.
