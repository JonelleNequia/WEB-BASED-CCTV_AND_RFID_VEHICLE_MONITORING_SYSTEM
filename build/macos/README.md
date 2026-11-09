# Manual offline package builds

Owner workflow (after the intended source has been reviewed and committed):
GitHub → Actions → **Manual offline package candidates** → Run workflow.
Enter the full 40-character source commit and one semantic version. The selected
commit must include these packaging files. The workflow checks out that same SHA
for Windows x64, Mac x86_64 and Mac arm64 and records one run/attempt identity.
There is no push trigger or automatic GitHub Release publication. Do not commit,
stage or discard unreviewed work just to enable a build.

Download all three artifacts from that run's Summary → Artifacts (14-day
retention). Each contains a complete archive, checksum and internal source/build/
content/native/dependency manifests. Compare commit, version, build identity,
canonical source hash and platform architecture across them. A partial failed
matrix is not a complete cross-platform release candidate. Hosted runner runtime
checks are not clean offline/reboot/device acceptance.

Current automation produces **unsigned acceptance candidates from clean committed
source**, labeled NOT-RELEASE/development. It never inserts secrets, accounts or
signing keys. Developer ID/notarization/Windows signing, maintenance/security and
redistribution approval plus D4/D5/D6 acceptance remain required before release.
The old Windows workflow is now reusable only from this manual workflow.

Local builder (macOS with Xcode command-line tools, build PHP/Composer, Node/npm,
Python/pip; all are build-machine requirements):

    python3 -B build/macos/build.py --arch arm64 --version 0.1.0-d3.1 --development --out /private/tmp/mac-arm-candidate --cache /private/tmp/mac-build-cache
    python3 -B build/macos/build.py --arch x86_64 --version 0.1.0-d3.1 --development --out /private/tmp/mac-intel-candidate --cache /private/tmp/mac-build-cache

Use new output directories. Development captures a frozen allowed working source
snapshot and appends its hash to the version. Build both architectures only after
editing stops; compare their source hashes. Cross-assembly audits Intel Mach-O
and native-library closure but does not execute Intel code on an arm64 host.
The native host leg executes packaged PHP, CPU prediction/tracking and OCR.

Pinned Homebrew **bottle inputs** supply PHP/FPM and selected dylibs; no Homebrew
installation is performed or needed on the target. The builder rewrites every
non-system PHP load path to packaged loader-relative files, thins universal
binaries, re-signs and audits native closure. CPython is the pinned Astral
standalone install-only runtime, not a copied development venv. All native wheels
are URL/SHA-pinned with full runtime closure plus explicit LAP for ByteTrack.
OpenCV's headless payload is selected deterministically as in D2. Models, local
HLS assets and notices are bundled. Tesseract/external FFmpeg/GPU are unavailable.

The installer keeps release code under ROOT/releases/VERSION and mutable state
under ROOT/shared, with a controlled ROOT/current link. D5 owns future staged
promotion, maintenance/migration/backup/rollback/transfer. Current installer
refuses changed-source and legacy installations and preserves same-source state.
See OFFLINE-INSTALL.md and NATIVE-ACCEPTANCE.md before any native test.
