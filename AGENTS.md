# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## Project Overview

This is a Composer library that provides two Behat contexts for running PHP's built-in web server during tests. It ships classes only - there is no CLI command and no binary.

- `PhpServerContext` serves static files from a configurable document root.
- `ApiServerContext` runs a small RESTful mock API server that returns queued responses, so tests can drive an application against predictable API behaviour.

Both contexts start a server before each scenario and stop it afterwards.

## Architecture

### Namespace structure

- Source code: `DrevOps\BehatPhpServer\` mapped to `src/DrevOps/BehatPhpServer/`
- Tests: `DrevOps\BehatPhpServer\Tests\` mapped to `tests/phpunit`
- Autoloading: PSR-4 via Composer, plus a classmap entry for `apiserver`

### Layout

- `src/DrevOps/BehatPhpServer/PhpServerContext.php` - the static file server context.
- `src/DrevOps/BehatPhpServer/ApiServerContext.php` - the mock API server context and its step definitions.
- `apiserver/index.php` - the mock API server itself, served by the PHP built-in server. It is part of the distributed package, not a test fixture, and is covered by both PHPCS and PHPStan.
- `tests/phpunit/Unit/` - unit tests.
- `tests/phpunit/Traits/` - shared test utilities such as `ReflectionTrait`.
- `tests/behat/features/` - the Behat feature files that exercise both contexts end to end.
- `tests/behat/fixtures/` and `tests/behat/fixtures2/` - fixture files used by the file-response steps. Two directories exist deliberately, to prove that multiple configured fixture paths are searched in order.

`apiserver/index.php` guards its own bootstrap with `SCRIPT_RUN_SKIP`. `phpunit.xml` sets that environment variable so the file can be loaded for unit testing without starting a server. Do not remove it.

## Commands

```bash
composer lint        # PHPCS, PHPStan, Rector (dry run), gherkinlint
composer lint-fix    # Rector, then PHPCBF
composer test        # PHPUnit, no coverage
composer test-bdd    # Behat
composer test-coverage  # PHPUnit with pcov coverage
```

Prefer these over calling the underlying binaries directly.

## Code quality standards

1. **PHP_CodeSniffer** - Drupal coding standards plus the DrevOps standard and a strict types requirement. Config: `phpcs.xml`. The `Drupal.Files.LineLength.TooLong` sniff is excluded.
2. **PHPStan** - level 9 across `src`, `apiserver` and `tests`. Config: `phpstan.neon`.
3. **Rector** - targets PHP 8.2, matching the `>=8.2` requirement in `composer.json`. Config: `rector.php`. Raise the Rector PHP set only when the composer constraint moves with it.
4. **gherkinlint** - lints the feature files. Config: `gherkinlint.json`.

### Coding conventions

- All PHP files must declare `strict_types=1`.
- Local variables and method arguments use `snake_case`.
- Method names and class properties use `camelCase`.
- Single quotes for strings, double quotes only when the string contains a single quote.
- All files end with a newline.

## Testing patterns

Coverage comes from two sources, so their outputs are kept apart: PHPUnit writes to `.logs/phpunit/` and Behat writes to `.logs/behat/`. Both are uploaded to Codecov. Keep those paths in sync between `phpunit.xml`, `behat.yml` and `.github/workflows/test-php.yml`.

Tests use PHPUnit 11 attributes:

- `#[CoversClass(ClassName::class)]` for coverage metadata.
- `#[DataProvider('providerMethodName')]` for data providers. Provider methods are named with a `dataProvider` prefix and placed after the test method they serve.

## CI

`.github/workflows/test-php.yml` runs the matrix PHP 8.2, 8.3, 8.4 and 8.5, against `normal` and `lowest` dependencies, on both `ubuntu-latest` and `macos-latest`. Both operating systems are tested on purpose - see Known issues.

Linting, the coverage threshold check and the Codecov uploads run once, on Ubuntu with PHP 8.4 and normal dependencies.

The `lowest` half of the matrix resolves every dependency to the floor its constraint allows, so it is sensitive to `config.policy.advisories.block` in `composer.json`. Leave that set to `true`. Setting it to `false` lets Composer select releases with known security advisories, and the floors it then reaches (Guzzle 7.9, `guzzlehttp/promises` 1.5, `symfony/http-client` 6.0) emit PHP 8.4 deprecations that Behat converts into step failures, so the whole BDD suite fails on PHP 8.4 and 8.5.

The coverage threshold check fails the build when coverage drops below 80%, and it reads the PHPUnit report only. Behat coverage does not count towards it even though Codecov merges both, so a change that is covered solely by a feature file will still push the measured number down. The threshold can be moved with the `CI_CODE_COVERAGE_THRESHOLD` repository variable.

## Known issues

Behat tests for the API server can fail on some environments because of connection issues. This usually comes down to port binding and process handling differing between operating systems, which is why CI covers both Linux and macOS.

## Workflow

- Use `feature/<name>` branches for pull requests.
- Commit messages start with a past-tense verb and end with a period, for example "Fixed the response queue ordering."

## Updating from the template

This project was generated from the [Scaffold](https://getscaffold.dev/) template and can pull the template's latest infrastructure (CI workflows, linting and test configuration, and docs) at any time. When you are asked to "update scaffold", fetch the updater skill from the template repository into the local `.claude/skills/` directory, then invoke it:

1. Create the target directory if it does not exist:

   ```bash
   mkdir -p .claude/skills/update-consumer-scaffold
   ```

2. Download the skill:

   ```bash
   curl -sSL https://raw.githubusercontent.com/AlexSkrypnyk/scaffold/main/.scaffold/skills/update-consumer-scaffold/SKILL.md -o .claude/skills/update-consumer-scaffold/SKILL.md
   ```

3. Invoke the `update-consumer-scaffold` skill and follow its steps.

The skill directory is fetched on demand and is git-ignored - it is not committed to the project.

Note that the template assumes a CLI command application or a single-file script. This project is a library, so an update run generates command stubs (`src/Command/`, a root-level `behat-phpserver` binary, and their functional tests) that must be deleted afterwards, and `composer.json` needs its `require`, `autoload` and `replace` entries preserved by hand.
