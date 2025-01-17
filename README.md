<div align="center">
  <a href="" rel="noopener">
  <img width=200px height=200px src="https://placehold.jp/000000/ffffff/200x200.png?text=Behat+PHP+server&css=%7B%22border-radius%22%3A%22%20100px%22%7D" alt="Yourproject logo"></a>
</div>

<h1 align="center">Behat contexts for serving static files and mocked API responses via the PHP server</h1>
<div align="center">

[![GitHub Issues](https://img.shields.io/github/issues/drevops/behat-phpserver.svg)](https://github.com/drevops/behat-phpserver/issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/drevops/behat-phpserver.svg)](https://github.com/drevops/behat-phpserver/pulls)
[![Test PHP](https://github.com/drevops/behat-phpserver/actions/workflows/test-php.yml/badge.svg)](https://github.com/drevops/behat-phpserver/actions/workflows/test-php.yml)
[![codecov](https://codecov.io/gh/drevops/behat-phpserver/branch/main/graph/badge.svg?token=KZCCZXN5C4)](https://codecov.io/gh/drevops/behat-phpserver)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/drevops/behat-phpserver)
[![Total Downloads](https://poser.pugx.org/drevops/behat-phpserver/downloads)](https://packagist.org/packages/drevops/behat-phpserver)
![LICENSE](https://img.shields.io/github/license/drevops/behat-phpserver)
![Renovate](https://img.shields.io/badge/renovate-enabled-green?logo=renovatebot)

</div>

## Features

- [`PhpServerContext`](src/DrevOps/BehatPhpServer/ApiServerContext.php) context
  to start and stop PHP server:
  - Automatically start and stop PHP server for each scenario.
  - Serve files from a configurable document root.
  - Configurable PHP server protocol, host and port.
- [`ApiServerContext`](src/DrevOps/BehatPhpServer/PhpServerContext.php) context
  to serve queued API responses for API mocking:
  - A RESTful [API server](apiserver/index.php) used to queue up expected API
    responses.
  - Step definition to queue up API responses.
  - Automatically start and stop PHP server for each scenario.
  - Serve files from a configurable document root.
  - Configurable PHP server protocol, host and port.

## Installation

    composer require --dev drevops/behat-phpserver

## Usage

### `PhpServerContext`

Used to serve assets from a pre-defined document root.

```yaml
default:
  suites:
    default:
      contexts:
        - DrevOps\BehatPhpServer\PhpServerContext:
            webroot: '%paths.base%/tests/behat/fixtures' # Path to the PHP server document root
            protocol: http  # PHP server protocol
            host: 0.0.0.0   # PHP server host
            port: 8888      # PHP server port
            debug: false    # Enable debug mode for verbose output
```

### `ApiServerContext`

Used to serve a pre-set API responses from a pre-defined document root.

```yaml
default:
  suites:
    default:
      contexts:
        - DrevOps\BehatPhpServer\ApiServerContext:
            webroot: '%paths.base%/apiserver' # Path to the API server document root
            protocol: http  # API PHP server protocol
            host: 0.0.0.0   # API PHP server host
            port: 8889      # API PHP server port
            debug: false    # API Enable debug mode for verbose output
```

API responses can be queued up in the API server server by sending
`PUT` requests to `/admin/responses` as an array of the expected responses
using following JSON format:

```json
[
  {
    "code": 200,
    "reason": "OK",
    "headers": {},
    "body": ""
  },
  {
    "code": 404,
    "reason": "Not found",
    "headers": {
    },
    "body": ""
  }
]
```

The `ApiServerContext` provides a step definition to make it easier to queue up
API responses:

```gherkin
# Queue up a single API response.
Given API will respond with:
"""
{
  "code": 200,
  "headers": {
    "Content-Type": "application/json"
  },
  "body": {
    "Id": "test-id-1",
    "Slug": "test-slug-1"
  }
}
"""

# Queue up a single API response with minimal configuration.
Given API will respond with:
"""
{
  "code": 200
}
"""
```

See this [test feature](tests/behat/features/apiserver.feature) for more
examples.

For more information on supported RESTful API enpoints, see
the [API server](apiserver/index.php) implementation.

#### Accessing the API server URL from your contexts

If you need to access the API server URL from your context to update the base
URL of your API client, you can do so by using `beforeScenario` in your
`FeatureContext` class:

```php
<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;

class FeatureContext implements Context {

  /**
   * The PHP server URL.
   */
  protected string $phpServerUrl;

  /**
   * The API server URL.
   */
  protected string $apiServerUrl;

  /**
   * Initialize the context.
   *
   * @beforeScenario
   */
  public function beforeScenarioInit(BeforeScenarioScope $scope): void {
    $environment = $scope->getEnvironment();

    if (!$environment instanceof InitializedContextEnvironment) {
      throw new \Exception('Environment is not initialized');
    }

    $context = $environment->getContext(PhpServerContext::class);
    $this->phpServerUrl = $context->getServerUrl();

    $context = $environment->getContext(ApiServerContext::class);
    $this->apiServerUrl = $context->getServerUrl();
  }

}

```

## Maintenance

### Lint code

```bash
composer lint
composer lint-fix
```

### Run tests

```bash
composer test
composer test-bdd
```

---
_This repository was created using the [Scaffold](https://getscaffold.dev/) project template_
