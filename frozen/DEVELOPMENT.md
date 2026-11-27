# Isolated development and tests

This is not the packaged installation path. Use a separate checkout/private SQLite,
storage, camera/device configuration and synthetic accounts. Never point fixtures
at working installation data or run reset/reseed commands on a school server.

Build-machine prerequisites include compatible PHP/Composer with development
vendor dependencies, Node/npm and Python plus build/test tools. `composer install`,
`npm ci`, `npm run build` and `npm run test:js` belong to this development/build
machine, not an offline target. Browser fixtures launch their own private PHP server
and explicitly refuse live database paths. Read [tests/browser/README.md](tests/browser/README.md).

Use the repository's isolated Laravel configuration and fixture environment for
PHPUnit; set private DB/storage/config/route cache and camera/device paths. Python
lifecycle/update/transfer fixtures create their own temporary hosts. Native tools
must not be invoked against working services or OS trust/network configuration.

Manual complete-package preparation is described in [build/delivery/README.md](build/delivery/README.md).
A clean reviewed source commit, signing and native acceptance remain release gates.
Development snapshots are frozen together; no automatic staging/commit/push is done.
