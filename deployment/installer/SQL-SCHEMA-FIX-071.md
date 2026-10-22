# 0.7.1 — SQL schema compatibility candidate

## Reproduced root cause

Executing the first migration on local SQL Server in a uniquely prefixed tempdb transaction failed on `departments.parent_id ON DELETE SET NULL`: SQL Server rejects cycles/multiple cascade paths. The transaction was rolled back and absence of probe tables verified.

## Explicit new-install policy

`SQLSERVER_RESTRICT_FOREIGN_KEYS=true` selects a SQL-only grammar which retains every FK but compiles cascading/nulling delete and update actions as NO ACTION. This is conservative: referenced-row deletion is blocked, not silently cascaded. Existing connections keep their original grammar unless this option is explicitly enabled. SQLite migrations are unchanged.

This changes delete semantics and is NOT equivalent to the SQLite cascade policy. Audit and test workflows that delete parents/children before production approval; application cleanup must be explicit and transactional. Do not disable/drop FKs to make deletion succeed.

## Safe retry of the failed empty install

Use a new local installation folder; retain the failed folder. Reuse the test SQL database ONLY when the preflight finds either no user objects, or precisely one dbo.migrations table with columns id/migration/batch, zero rows, and no other user table/view/procedure/function/trigger/sequence/synonym. The second case requires the explicit GUI retry checkbox. No tables are dropped or reset. Any other partial schema is rejected for manual diagnosis.

## Diagnostics

Failure output contains an allowlisted stage, safe migration basename, SQLSTATE, numeric native error and reason code only. No raw SQL/driver exception, password or business values are emitted. After installation starts, sanitized diagnostics are stored in sql-install-diagnostic.json.

## Verified

- Original SQL cascade failure reproduced; rollback verified.
- Policy opt-in, complete first-migration SQL compilation, FK retention: 3 tests / 47 assertions passed.
- Empty-schema/retry decisions: 9 cases passed.
- CLI pre-write guards, dotenv escaping, auth isolation and diagnostic redaction passed.
- Application regression suite before final added compilation test: 202 tests / 1896 assertions passed (SQLite, not SQL).
- Fresh portable package SQLite initialization/login/customer/care/restart-persistence passed using the new source snapshot.

## Not verified

Full 65-migration execution was attempted but the local SQL instance returned a query timeout on CREATE TABLE and later a shared-memory connection timeout. Do not mark that run successful. Server VKCRM_Test was not modified by these automated tests.

Production approval still requires SQL schema execution, Admin/login, customer/personnel workflows, deletion/permissions, backup/restore, supported runtime and clean-machine testing. This candidate is not a fully accepted customer release.
