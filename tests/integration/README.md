# Optimization Integration Tests

These tests use small fixtures created through DuskRail's own discovery and crawl methods. They refuse the configured application databases.

Create a private directory with `mktemp -d /tmp/duskrail-optimization-tests.XXXXXX`. Start disposable MariaDB and Manticore instances inside it, with memory limits:

- MariaDB: data directory `data/`, socket `db.sock`, no TCP listener, root with an empty password.
- Manticore: data directory `search-data/`, MySQL socket `search.sock`, no TCP listener.

Run `php tests/integration/optimization-test.php /tmp/duskrail-optimization-tests.XXXXXX` with the actual directory. The test checks MariaDB's data directory before creating a fresh test database, and replaces only the disposable Manticore instance's `duskrail_*` tables. Stop both disposable instances and remove their scratch directory afterwards.

The dependency-free suites run separately:

```sh
php bin/test.php
node tests/search-grid-test.js
```
