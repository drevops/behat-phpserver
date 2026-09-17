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

`composer install` sets up Behat 3. To run the suites on Behat 4, switch to it first:

    composer update --with=behat/behat:^4
    composer test-bdd

`composer update --with=behat/behat:^3` switches back to Behat 3.

`composer install` also sets up Guzzle 8. The package supports Guzzle 7 as well, so to check a change against it, switch to it first:

    composer update --with=guzzlehttp/guzzle:^7
    composer test
    composer test-bdd

`composer update --with=guzzlehttp/guzzle:^8` switches back to Guzzle 8. Each `composer update` resolves every package again, so combine both `--with` flags to run Behat 4 on Guzzle 7.

Switch back to Guzzle 8 before you run `composer lint`. The tests use generic types that only Guzzle 8 declares, such as `HandlerStack<...>`, so PHPStan reports them as errors on Guzzle 7.

`composer test-coverage` writes the PHPUnit coverage report to `.logs/phpunit/`. `composer test-bdd` writes the Behat coverage report to `.logs/behat/` whenever pcov or Xdebug is enabled.

The Behat suite binds real ports, so it can fail on machines where those ports are already in use, or where process handling differs. If a run fails to connect, check that ports 8888 and 8889 are free.

## Maintenance

This project is generated from the [Scaffold](https://getscaffold.dev/) template and can pull the template's latest CI workflows, linting and test configuration at any time. See [`AGENTS.md`](AGENTS.md) for the update procedure and the manual reconciliation it needs.
