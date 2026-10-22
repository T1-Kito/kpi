# SQL installer trial candidate

This is an engineering trial, not a production release. Normal startup still blocks SQL installation.

## Changes

- Explicit `--sql-trial` mode enables the new SQL installation flow.
- Use an EXISTING, empty, disposable database named `VKCRM_...`. The installer does not create databases.
- Requires db_owner visibility (or sysadmin for engineering tests) to avoid interpreting invisible objects as an empty database. A production runtime account with restricted permissions is still outstanding.
- Validates settings, rejects system/customer database names, requires trial confirmation and refuses an existing .env or interrupted-install marker.
- Acquires a database-scoped application lock and rechecks emptiness. Never drops tables or resets databases.
- Creates schema and Admin; only success emits SQL_INSTALL_OK. Failures retain partial schema/configuration for diagnosis; no automatic retry or cleanup.
- Installs the matching PDO driver, restricts new install directory ACL to installing user, SYSTEM and Administrators, and transmits credentials over stdin.
- Installed manager supports SQLite and SQL markers and never requires a local SQLite database for SQL installs.

## Verification completed

- PHP syntax checks pass.
- 7 invalid-settings cases, 11 dotenv escaping round trips, Windows-auth isolation pass.
- 6 real CLI pre-write rejection cases pass, without a DB connection or configuration writes.
- WinForms compilation and automatic wizard self-test pass.
- Read-only localhost\MSSQLSERVER01/master connection and Vietnamese Unicode pass.
- Schema compilation audit previously compiled 65 migrations / 705 statements. This is not schema execution verification.

## Still required before approval

- Execute migrations on an isolated SQL database, initialize Admin, login, customer/personnel permissions, restart/persistence, concurrency and backup/restore.
- Disk capacity check for SQL trial: minimum 5 GiB workspace free; do not bypass this to force a test on the current machine.
- New supported PHP runtime, clean Windows test, production service/HTTPS, reduced runtime SQL permissions, upgrade/restore design, code protection/license/signing as separately agreed.

No production readiness is implied by a successful build or connection check. Do not give --sql-trial to an end customer as an approved installation mode.
