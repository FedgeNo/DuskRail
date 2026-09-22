# Optimization Integration Tests

These tests use small fixtures created through DuskRail's own discovery and crawl methods. They refuse the configured application databases.

Create a private directory with `mktemp -d /tmp/duskrail-optimization-tests.XXXXXX`. Start disposable MariaDB and Manticore instances inside it, with memory limits:

- MariaDB: data directory `data/`, socket `db.sock`, no TCP listener, root with an empty password.
- Manticore: data directory `search-data/`, MySQL socket `search.sock`, no TCP listener.

Run `php tests/integration/optimization-test.php /tmp/duskrail-optimization-tests.XXXXXX` with the actual directory. The test checks MariaDB's data directory before creating a fresh test database, and replaces only the disposable Manticore instance's `duskrail_*` tables. Stop both disposable instances and remove their scratch directory afterwards.

`php tests/integration/thumbnail-budget-test.php /tmp/duskrail-optimization-tests.XXXXXX` requires only the disposable MariaDB instance. It uses network/decoder stubs to verify shared lock ownership throughout processing, capacity recovery, and exception cleanup without fetching remote images.

The dependency-free suites run separately:

`php tests/integration/redirect-priority-test.php /tmp/duskrail-optimization-tests.XXXXXX`
requires only the disposable MariaDB instance. It checks priority transfer and
SourceLedger aliases across redirect merges, with search and thumbnail side
effects stubbed so application services and files remain untouched.

```sh
php bin/test.php
node tests/search-grid-test.js
node tests/thumbnail-retry-test.js
```
