# Closed production-issue regression audit

Reviewed all four closed GitHub issues returned for `RedberryProducts/synapse` on 2026-10-23. The existing regression assertions remain in place. This audit covers this working branch on PHP 8.4 with SDK 1.3.0; the wider release matrix remains step 8.

| Closed issue | Retained regression coverage | Additional fresh-app evidence | Result |
|---|---|---|---|
| [#7 — Truncate long agent names with an ellipsis](https://github.com/RedberryProducts/synapse/issues/7) | `tests/Browser/DiscoveryTest.php`: available/unavailable discovery cards and sidebar at desktop and 420px mobile width; ellipsis, full title, and overflow assertions | Created available and unavailable long-name agents; both appear in discovery | Passed |
| [#8 — Show a loading indicator while waiting for the first stream event](https://github.com/RedberryProducts/synapse/issues/8) | `tests/Browser/ChatTest.php`: delayed first event, replacement without duplicate messages, failure before streaming, preservation of prior reasoning | Observed loading before the preview middleware's first event over real HTTP | Passed |
| [#9 — Prevent the installed Synapse provider from breaking --no-dev deployments](https://github.com/RedberryProducts/synapse/issues/9) | `tests/Feature/InstallTest.php`: guarded registration, repeat installation, package absence, legacy registration handling | Installed twice with identical provider-file hashes, removed all dev dependencies, confirmed Synapse absent from autoload, booted Laravel and cached production config | Passed |
| [#10 — Prevent published Synapse migrations from failing after composer install --no-dev](https://github.com/RedberryProducts/synapse/issues/10) | `tests/Feature/AiStorageMigrationTest.php`: published migrations run without Synapse/SDK autoloading; configured storage connection remains covered | With Synapse actually removed, ran all five published migrations from scratch on a separate production-check SQLite database | Passed |

The full gates passed after the discovery changes: **`composer check`: 289 tests / 916 assertions**, plus Pint and PHPStan; **`composer test:e2e`: 81 tests / 310 assertions**. Browser tests continue to use fake agents and make no provider API requests.

The actual deployment rehearsal, restore commands, preview fixture corrections, and manual checks are recorded in [INSTALLATION-WALKTHROUGH.md](INSTALLATION-WALKTHROUGH.md). No GitHub issues were reopened or modified.
