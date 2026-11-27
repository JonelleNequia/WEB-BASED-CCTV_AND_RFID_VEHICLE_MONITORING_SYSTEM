# Exact D6 release-source snapshot

This branch contains the sanitized **frozen development source**, not complete
offline installers. Use the named testing-release assets for installation.

The `frozen/` directory reproduces all 873 captured source files exactly; verify
with SOURCE-INFO.json. Its base commit is `a471eed4a7186466114ff4ed0c80a634ca3d81c4` and canonical
source hash is `ecc499bd0d7af5ef1309f5db7cd3e2eaac7f2c4fb7241fdc2135790428cf810e`. This is not a claim that the binary
packages were built from a clean Git commit. New provenance/licensing documents
are post-build supplements and do not rewrite the binary metadata.

Historical databases, backup/snapshot archives, model/runtime binaries, generated
bytecode, installed secrets, setup deliveries and private evidence are excluded.
Existing repository branches/history are left unchanged. The supplied source.tar.gz
is the authoritative full 873-file capture; automatic GitHub Source code downloads
may obey export-ignore rules and are never complete offline installers.

Build inputs are pinned in frozen/build/. For exact development reproduction use
the frozen builder and its explicit --frozen-source directory; new source changes
require a new identity. Native installed acceptance and production approval remain
separate. No production compatibility origins are approved by publication.

Original project code is licensed under AGPL-3.0-only; see LICENSE and
PROJECT-LICENSING.md. Third-party material retains its own notices.
