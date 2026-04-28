# LibEuFinConnector Test Inventory and Design Notes

This file tracks the current test suites for `libeufinconnector`, what they cover,
and how they are intended to run in CI.

## Test packaging

The target execution model follows the existing `talerbarr` packaging:

1. Dolibarr core PHPUnit suites run through `test/phpunit/AllTests.php`.
2. `libeufinconnector` static checks run next.
3. `libeufinconnector` integration suites run last.
4. A dedicated Podman runner provisions a clean Dolibarr instance plus LibEuFin packages.

The LibEuFin-specific container deliberately avoids GNU Taler services. It installs
`libeufin-common` and `libeufin-nexus` only.

## Static / unit-like suites

1. `test/phpunit/unit/LibeufinTransactionStaticTest.php`
- Covers deterministic helper behavior on the staging model.
- Verifies direction/status normalization, payload normalization, and dedupe stability.

## Integration suites

These suites bootstrap real Dolibarr classes and database state, then exercise
`libeufinconnector` workflow functions against real objects.

1. `test/phpunit/integration/LibeufinIncomingCustomerPaymentIntegrationTest.php`
- Creates a real customer invoice.
- Creates a staged incoming transaction whose message contains the invoice ref.
- Verifies strict incoming matching creates:
  - customer payment
  - bank line
  - staged links

2. `test/phpunit/integration/LibeufinIncomingSupplierRefundIntegrationTest.php`
- Creates a real supplier credit note.
- Creates a staged incoming transaction whose message contains the supplier credit-note ref.
- Verifies strict supplier-refund matching creates:
  - native `SPAY...` supplier payment
  - bank line
  - staged links

3. `test/phpunit/integration/LibeufinOutgoingCollectionIntegrationTest.php`
- Creates:
  - a real supplier invoice payment
  - a real customer credit-note refund payment
- Runs outgoing collection.
- Verifies staged outgoing rows are created for both transaction families with the
  expected source-specific external identifiers and Dolibarr links.

## CI wiring

`devtools/podman/run-tests-podman.sh` builds and runs the module test container.

Inside the container:
- Dolibarr demo database is loaded and upgraded.
- `libeufinconnector` is staged into `htdocs/custom/libeufinconnector`.
- LibEuFin packages are installed and checked for availability.
- the runner executes three explicit phases:
  1. `phpunit -c test/phpunit/phpunittest.xml test/phpunit/AllTests.php`
  2. `parallel-lint` plus `LibeufinTransactionStaticTest`
  3. the three LibEuFin integration suites one by one

This keeps the run order aligned with the user requirement:
- Dolibarr tests first
- module static tests next
- module integration tests last
