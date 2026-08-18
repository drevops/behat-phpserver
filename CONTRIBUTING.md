# Contributing

Thank you for considering a contribution to this project. This guide covers setting up a local environment and running the linting and tests.

## Setup

    composer install

## Linting

`composer lint` runs PHPCS, PHPStan, Rector in dry-run mode, and gherkinlint over the feature files. `composer lint-fix` applies the fixes that Rector and PHPCBF can make automatically.

    composer lint
    composer lint-fix

## Tests

There are two suites. PHPUnit covers the classes, and Behat exercises both contexts end to end by actually starting the servers.

    composer test
    composer test-bdd

To produce coverage reports, run `composer test-coverage`. PHPUnit writes to `.logs/phpunit/` and Behat writes to `.logs/behat/`.

The Behat suite binds real ports, so it can fail on machines where those ports are already in use, or where process handling differs. If a run fails to connect, check that ports 8888 and 8889 are free.

## Maintenance

This project is generated from the [Scaffold](https://getscaffold.dev/) template and can pull the template's latest CI workflows, linting and test configuration at any time. See [`AGENTS.md`](AGENTS.md) for the update procedure and the manual reconciliation it needs.
