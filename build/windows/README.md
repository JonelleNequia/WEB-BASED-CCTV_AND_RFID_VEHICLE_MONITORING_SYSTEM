# Windows D2 complete offline candidate builder

Read [OFFLINE-INSTALL.md](OFFLINE-INSTALL.md) for target installation and limits,
and [ci/NATIVE-ACCEPTANCE.md](ci/NATIVE-ACCEPTANCE.md) for actual packaged acceptance.
No target Git/Composer/Node/Python/PHP downloads are needed. Build machines may use
internet and require PowerShell 7, Git, Composer/PHP, Node 22.18+, Python/pip and
(optional, Windows only) Inno Setup 6.3+. Native acceptance is separate from build.

Dirty release builds are refused. Nothing is staged, committed or discarded by
the builder. All app/build/template/lock inputs are captured into one external
snapshot before assembly. Development snapshots include current approved-for-test
working files and carry exact content hashes, explicitly marked NON-RELEASE.

```powershell
# Non-release cross-assembly / local candidate; never operate the live install.
pwsh build/windows/build-release.ps1 -Version 0.1.0-d2.1 -DevelopmentSnapshot -Python python3 -OutDir C:\build\candidate -CacheDir C:\build\cache
# Windows only: add -Installer (ISCC.exe in PATH) for the EXE.
# Release requires a CLEAN explicitly approved source commit, native checks,
# institutional redistribution approval and a publisher certificate in the build
# machine's CurrentUser certificate store. Private keys never enter artifacts.
pwsh build/windows/build-release.ps1 -Version 1.0.0-rc.1 -SourceCommit FULL_SHA -Python python -RedistributionApproved -SigningCertificateThumbprint APPROVED_THUMBPRINT -Installer
```

Default outputs are `build/windows/dist/PHILCST-VMS/`, a versioned `*-win64.zip`,
its SHA-256 sidecar and (when native Inno is available) Setup EXE/checksum.
Development version names include `-dev.<source-hash>`. Existing staging folders
are refused rather than deleted. Use a new OutDir. A cross-built ZIP has native
validation **NOT RUN**, not a Windows compatibility claim. No automatic publish.

Package contents: production app/vendor/static and built assets; PHP + DLLs;
embeddable Python + 57 locked SHA-verified Windows distributions; deterministic
headless cv2 payload; local YOLO/EasyOCR/plate ONNX models; Caddy/go2rtc/NSSM;
immutable Microsoft VC++ redistributable; readiness scripts/recorded fixture;
input/source/content manifests, dependency/license inventory and notices.
Optional Tesseract/standalone FFmpeg/GPU capabilities are unavailable in D2.

Runtime inputs are immutable/hash-pinned in `runtimes.json`. CPU wheel URLs/hashes
are in `wheels-win64.json` and the install lock; the builder checks dependency
closure and then installs only from its verified wheelhouse. VC++ additionally
requires its Microsoft Authenticode signature on Windows, even when cached.
The assembled boundary and extracted ZIP bytes are checked. Detached manifest
RSA signing and EXE Authenticode signing are available; unsigned development
artifacts are not publisher authenticated. Redistribution/model licensing and
D4/D5 acceptance remain gates, even if a ZIP was successfully assembled.
