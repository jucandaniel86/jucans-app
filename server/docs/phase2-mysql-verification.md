# Phase 2: isolated MySQL verification

## Result

Phase 2 has been exercised on the actual local **MySQL 8.2.0** server, using InnoDB. Migration, native integrity, historical-value, authorization, rollback and separate-process concurrency checks passed. Backend Phase 2 is ready for Progress Note frontend integration against this contract. Deployment still requires the production prerequisites listed below; no development or production schema was migrated.

## Isolation and retained local setup

The exact schema `jucans_phase2_mysql_test` did not exist before setup. A unique account was checked for absence and created specifically for it. Neither an existing schema nor an existing account was repurposed.

The account has only SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES and TRIGGER privileges on this exact schema. Schema wildcard characters were escaped when granting privileges; the account has no global privileges, roles, grant option or application-database access. Secure generated credentials were written only after `git check-ignore` confirmed the private file was ignored; it remains mode 0600. Credentials were never passed in process arguments or printed.

Local files:

- `.mysql-testing.local.json`: ignored private connection identity and credentials; retained for future runs. Do not commit or share it.
- `.mysql-testing.example.json`: committed placeholders only; not a working credential file.
- `phpunit.mysql.xml`: explicit MySQL test configuration, separate from unchanged `phpunit.xml` SQLite configuration.
- `tests/mysql-bootstrap.php`: loads private configuration, forces APP_ENV=testing, pins the database/account/socket, and clears DATABASE_URL/DB_URL before Laravel boot.

`tests/Support/IsolatedDatabase.php` verifies the environment, effective connection, exact schema, restricted account, local socket, server UUID, enabled foreign keys and actual grants before RefreshDatabase/migrations can run. Cached application configuration and read/write connection overrides are forbidden. Explicit MySQL mode cannot silently fall back to SQLite. SQLite remains allowed only as :memory: in its own workflow. Guard tests verify unsafe targets fail without opening database connections.

The local setup remains available. Run from `server`:

```sh
php vendor/bin/phpunit -c phpunit.mysql.xml --filter='Project|NoteValueMiddlewareTest|IsolatedDatabaseGuardTest'
```

The ordinary SQLite command remains:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DATABASE_URL= DB_URL= php vendor/bin/phpunit --filter='Project|NoteValueMiddlewareTest|IsolatedDatabaseGuardTest'
```

Do not use Laravel development configuration or another schema for these commands. Tests intentionally recreate disposable tables. Do not run simultaneous suite invocations against this single test schema.

## Approved administrative DDL exception

The local server has binary logging enabled with `log_bin_trust_function_creators=0`. A real restricted-account migration attempt failed with MySQL error 1419 when creating the Phase 2 trigger despite schema-level TRIGGER privileges.

The user explicitly approved local-administrator migration/trigger DDL **only inside the disposable schema**, while application/concurrency operations remain restricted. No global privileges were granted and no server-global settings were changed.

`tests/Support/IsolatedMySqlConnection.php` implements that test-only exception. It delegates CREATE/ALTER/DROP/TRUNCATE table/index/view/trigger DDL to the separately configured local administrator, pinned to the same schema/socket/server identity. It rejects other SQL and cross-schema qualifiers before opening the administrator connection. The local file must explicitly authorize delegation. Administrator credentials also stay in that ignored file. Unit tests verify rejection of unsafe targets and non-DDL.

All application SELECT/INSERT/UPDATE/DELETE, transactions and row locks use the original restricted PDO. Concurrency workers do not open the administrative PDO. During migrate:fresh cleanup, foreign-key checking is toggled only on the two disposable-test sessions and restored; server-global settings are untouched. No test helpers are registered by production bootstrap.

This verifies native MySQL DDL and behavior, **not** that the restricted account can independently create triggers under the current binary-log policy. Future test runs require the approved local DDL account to remain available.

## Migration and native integrity results

The prerequisite migrations and exact Phase 2 MySQL branch were applied on the disposable schema. A separate committed-fixture migration test removes Phase 2, inserts a legacy note into the prerequisite schema, applies Phase 2, and exercises down/reapply again. The note's content/date remain, configuration/type/capture columns remain null and no values are backfilled.

The migration round trip does not run inside RefreshDatabase's transaction: MySQL DDL implicitly commits. The shared disposable-migration trait performs guarded db:wipe cleanup rather than rolling back unrelated old SQLite migrations that do not support individual FK drops.

Native MySQL checks verify:

- InnoDB on the relevant tables and actual DECIMAL(20,6) storage.
- Both CHECK constraints exist with ENFORCED=YES.
- Typed-column and Boolean-range failures return native CHECK error 3819.
- Project-scoped and option-ownership FK violations return native FK errors 1216/1452 with SQLSTATE 23000.
- Duplicate note/field values return error 1062.
- Restrictive deletes return native FK errors 1217/1451.
- The actual immutable-snapshot trigger exists, and changes to frozen context return its SIGNAL error 1644.
- Incomplete inserted configuration context fails its CHECK.
- Large exact decimals, numeric zero, Boolean false, null/clearing and whitespace-preserving Text round-trip through the API.

The Project/configuration/note/evaluation API regressions run under the restricted account, including owner/collaborator policies, historical snapshots, lifecycle changes, first-value behavior, eager loading and atomic rollback.

## Separate-process concurrency results

Eight tests exercise ten blocking scenarios. Fixtures are committed and there is no enclosing RefreshDatabase transaction. The PHPUnit process and a separate PHP worker use distinct MySQL connection IDs and the restricted account. A barrier reports the worker's attempted project SELECT FOR UPDATE; while the parent holds that lock, no relevant configuration read or completion is permitted. The parent then commits or rolls back and the worker proceeds. Configuration reads are confirmed to occur after the lock attempt succeeds.

Passed scenarios:

1. A configuration mutation commits before waiting typed-note creation: the snapshot sees the new template, field label, Required flag and order coherently.
2. A note captures first, then a waiting configuration mutation commits: the saved snapshot retains its original metadata.
3. Field archive, type change and unit change each commit before a waiting value write: the write rejects with 409, with no partial title/value persistence.
4. A value and first marker commit before a waiting incompatible unit mutation: the configuration change rejects with 422.
5. Partial updates to different fields of the same note preserve both values, including false.
6. Competing same-field writes preserve documented last-write-wins ordering.
7. Concurrent first values across two notes keep the first successful field marker.
8. A first-value transaction rolls back while another writer waits: rolled-back values disappear and the waiting successful writer initializes the marker at its own timestamp.

No Phase 2 deadlock or inconsistent/partial persistence was observed in these scenarios. These are deterministic blocking tests on MySQL 8.2.0, not a general stress/load test.

## Fixes

1. **first_value_at microseconds:** the real concurrency rollback test exposed `.000000` instead of the supplied microseconds. Eloquent's bulk-update datetime binding used second precision. The service now submits an explicit UTC `Y-m-d H:i:s.u` string. API and concurrency tests assert exact microseconds while retaining never-overwrite/never-clear semantics.
2. **Middleware scope:** the previous wildcard exempted additional note lifecycle paths/methods. The exception now applies only to POST `/api/projects/{numeric-id}/notes` and PATCH `/api/projects/{numeric-id}/notes/{numeric-id}`, and only exact `values.<index>.value` keys. Other keys, methods, lifecycle paths and unrelated routes retain normal trim/empty-to-null behavior. Boolean false and numeric zero are unchanged.
3. **Portable rollback fault tests:** API tests now inject late failures through scoped model events, avoiding SQLite-only trigger syntax and MySQL DDL implicit commits inside transaction-wrapped tests. Actual database constraints/triggers remain separately tested with native errors.
4. **Migration test isolation:** Phase 2 up/down/reapply moved to a separate committed-fixture test with guarded disposable cleanup. SQLite and MySQL workflows both remain supported.

## Verification results

- Relevant MySQL suite: **111 passed, 1,653 assertions**, no skipped tests.
- Migration/concurrency cleanup recheck: **9 passed, 125 assertions**.
- SQLite workflow: **100 passed, 11 intentional MySQL-only skips, 1,508 assertions**.
- Separate unrelated MySQL Diet/Recipe run: **36 passed, 5 failed**. Four DietGeneralInfoApiTest failures are HTTP 405 for the absent expected update route; RecipeApiTest's list uses 5 queries against a budget of 4. These reproduce the existing failures and were not fixed.
- Targeted PHP syntax, Laravel Pint, Git whitespace and private-file ignore/mode checks passed.

No frontend controls, database schema/migration SQL changes, chart/metric behavior, recurrence changes, unrelated evaluation logic, Diet/Recipe fixes or dependencies were introduced.

## Files created/modified in this verification task

Created:

- `phpunit.mysql.xml`, `.mysql-testing.example.json`.
- `tests/mysql-bootstrap.php`.
- `tests/Support/IsolatedDatabase.php`, `IsolatedMySqlConnection.php`, `DisposableDatabaseMigrations.php`, `MySqlWorker.php`, `mysql-worker.php`.
- `tests/Feature/Api/ProjectProgressNoteMigrationTest.php`, `ProjectProgressNoteMySqlConcurrencyTest.php`.
- `tests/Feature/NoteValueMiddlewareTest.php`, `tests/Unit/IsolatedDatabaseGuardTest.php`.
- This report. The private ignored local configuration was also provisioned and retained.

Modified:

- `.gitignore`: exclude the private local JSON file.
- `tests/CreatesApplication.php`: enforce the safety guard before database test traits run.
- `tests/Feature/Api/ProjectProgressNoteValuesApiTest.php`: retain safeguards through the shared guard, add native MySQL coverage/exact marker assertion, and move DDL/portable fault cases as described above.
- `app/Services/ProjectProgressNoteValuesService.php`: preserve marker microseconds.
- `app/Http/Middleware/TrimStrings.php`, `ConvertEmptyStringsToNull.php`: narrow the value-path exception.
- `docs/project-note-values-phase-2.md`: link this follow-up verification report.

## Deployment prerequisites still outstanding

Development and production remain unmigrated. Rehearse deployment on the actual target version with backups and verified Phase 1 migration history/index prerequisites. Require supported MySQL (minimum 8.0.16; this run used 8.2.0), InnoDB, enforced CHECK/FK constraints, UTC conventions and appropriate migration DDL privileges. Binary-logged servers may require an approved privileged migration/trigger creator under their existing policy; do not grant global privileges to the application user to work around error 1419.

Apply the Laravel migration **or** the equivalent manual SQL with correctly reconciled migration history, never both. Do not use the destructive down migration as a data-preserving rollback. The frontend must use each note's frozen configuration, merge partial values and preserve decimal strings and strict Booleans/option IDs. Same-field updates remain last-write-wins; no optimistic version contract was added.
