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
- `tests/phpunit/Traits/` - shared test utilities such as `ReflectionTrait` and `BehatDefinitionTrait`.
- `tests/behat/features/` - the Behat feature files that exercise both contexts end to end.
- `tests/behat/fixtures/` and `tests/behat/fixtures2/` - fixture files used by the file-response steps. Two directories exist deliberately, to prove that multiple configured fixture paths are searched in order.
- `behat.yml` and `behat.php` - the test suite configuration. Behat 3 reads `behat.yml` before any PHP file, and Behat 4 reads PHP configuration only, so each Behat major runs the suite from its own file. A change to the suite goes in both.
- `behat.dist.yml` and `behat.dist.php` - both contexts with every option set, as a reference for anyone configuring the package. Behat never loads them in this repository, because `behat.yml` and `behat.php` take precedence. `BehatDistConfigTest` fails when the 2 files differ or when either one misses a constructor option.

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
3. **Rector** - targets PHP 8.3, matching the `>=8.3` requirement in `composer.json`. Config: `rector.php`. Raise the Rector PHP set only when the composer constraint moves with it.
4. **gherkinlint** - lints the feature files. Config: `gherkinlint.json`.

### Coding conventions

- All PHP files must declare `strict_types=1`.
- Local variables and method arguments use `snake_case`.
- Method names and class properties use `camelCase`.
- Single quotes for strings, double quotes only when the string contains a single quote.
- All files end with a newline.
- Step methods on `ApiServerContext` are named `api` plus the step phrase in camelCase, with the `API` / `API server` token folded into the prefix - `the API server is reset` becomes `apiIsReset()`. The step attribute is the published contract and the method name is derived from it, so renaming a method never means rewriting its step phrase.
- Hooks and step definitions are declared with PHP attributes, such as `#[BeforeScenario]` and `#[Given('(the )API server is running')]`, never with docblock annotations. Behat 4 doesn't read annotations at all, so on Behat 4 an annotated hook never runs and an annotated step is reported as undefined. `testDeclaresNoBehatAnnotations()` in both context tests fails on any Behat annotation, and `testStepAttributes()` pins every published step phrase.

## Testing patterns

Coverage comes from two sources, so their outputs are kept apart: PHPUnit writes to `.logs/phpunit/` and Behat writes to `.logs/behat/`. Both are uploaded to Codecov. Keep those paths in sync between `phpunit.xml`, `behat.yml`, `behat.php` and `.github/workflows/test-php.yml`.

`behat.yml` and `behat.php` run the suite in the `gherkin-32` compatibility mode, where tag names keep their leading `@`, which is how Behat 4 parses by default. Both also turn on strict mode, so a step with no matching definition fails the run instead of being reported as undefined and passing.

The Gherkin parser caches parsed features by file path and Gherkin version, not by parsing mode, so a feature parsed in one mode can be served to a run in another, and that run passes for the wrong reason. That's why both suite files point the Gherkin cache at a directory named after the mode, `.artifacts/tmp/gherkin-cache/gherkin-32`. If you change the compatibility mode, change the cache directory with it. `BEHAT_PARAMS` can't override either setting, because values in the configuration file take precedence over it.

Tests use PHPUnit 12 attributes:

- `#[CoversClass(ClassName::class)]` for coverage metadata.
- `#[DataProvider('providerMethodName')]` for data providers. Provider methods are named with a `dataProvider` prefix and placed after the test method they serve.

Build a test double with `createStub()` or `getStubBuilder()` unless the test calls `expects()` on it. PHPUnit 12.5 reports a notice for every mock object that has no expectation, so a mock is only worth creating when the test asserts how it's called. `ApiServerContextTest` follows the same split: `createContextWithClient()` returns a stub, and `createMockContextWithClient()` returns a mock for the tests that assert on `isRunning()` and `start()`.

## CI

`.github/workflows/test-php.yml` runs the matrix PHP 8.3, 8.4 and 8.5, against Behat 3 and Behat 4, with `normal` and `lowest` dependencies, on both `ubuntu-latest` and `macos-latest`. Both operating systems are tested on purpose - see Known issues.

Each job picks its Behat major with `composer update --with="behat/behat:^3"` or `^4`. Composer combines that temporary constraint with the `^3.32.0 || ^4.0@alpha` range in `composer.json` rather than replacing it, so the `lowest` jobs still start from the `composer.json` floors. The dev constraints allow both majors for the same reason: `friends-of-behat/mink-extension` is `^2.7.5 || ^3.0@alpha`, and `dvdoug/behat-code-coverage` is `^5.3.7`, since 5.5.0 is its first release that allows Behat 4. `prefer-stable` keeps a plain `composer install` on Behat 3.

The Behat 4 `lowest` jobs also pass `--with=symfony/dependency-injection:^6.4`. Without it, Composer pairs `symfony/config` 7.4, which `friends-of-behat/mink-extension` 3.0 requires, with `symfony/dependency-injection` 5.4. Neither package declares a conflict with the other, but their `FileLoader::import()` signatures are incompatible, so Behat stops with a fatal error before it runs a scenario.

Job names follow `PHP <version>, Behat <major>, Deps <dependencies> on <os>`, for example `PHP 8.4, Behat 4, Deps lowest on ubuntu-latest`. The `main` ruleset requires every job by that name, plus `codecov/patch` and `codecov/project`, so a change to the job names needs the same change to the ruleset's required status checks.

Linting runs on Ubuntu with PHP 8.4 and normal dependencies, once per Behat major, so PHPStan checks the code against both. The coverage threshold check and the Codecov uploads run once, on the Behat 3 job of that combination.

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
