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
            paths:          # Path(s) to fixture files for API responses
              - '%paths.base%/tests/behat/fixtures'
              - '%paths.base%/tests/behat/fixtures2'
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

The `ApiServerContext` provides several step definitions to make it easier to
work with the API server:

```gherkin
# Check if the API server is running.
Given the API server is running

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

# Queue up a single API response with JSON body.
Given API will respond with JSON:
  """
  {
    "Id": "test-id-1",
    "Slug": "test-slug-1"
  }
  """

# Queue up a single API response with JSON body and expected code.
Given API will respond with JSON and 201 code:
  """
  {
    "Id": "test-id-2",
    "Slug": "test-slug-2"
  }
  """

# Reset the API server by clearing all responses and requests.
Given the API server is reset

# Queue up a file response with automatic content type detection.
Given API will respond with file "test_data.json"

# Queue up a file response with a custom response code.
Given API will respond with file "test_content.xml" and 201 code

# Assert the number of requests received by the API server.
Then the API server should have 3 received requests

# Assert the number of responses queued in the API server.
Then the API server should have 0 queued responses
```

See this [test feature](tests/behat/features/apiserver.feature) for more
examples.

### Using File Responses

The `apiWillRespondWithFile` step definition allows you to respond with the contents of a file
from one of the configured fixture paths. The context will automatically detect the appropriate
content type based on the file extension:

- `.json` → `application/json`
- `.xml` → `application/xml`
- `.html`, `.htm` → `text/html`
- `.txt` → `text/plain`
- All other extensions → `application/octet-stream`

Multiple fixture paths can be configured in the `behat.yml` file. The context will search for the
file in each path in the order specified until it finds a match.

### Resetting the API Server

The `resetApi` step definition allows you to clear all queued responses and request history in the API server.
This is useful for ensuring a clean state between test steps, especially when multiple scenarios
interact with the API server:

```gherkin
# Clear existing state before setting up a new test
Given API server is reset
And API will respond with file "test_data.json"
When I send a GET request to "/"
```

For more information on supported RESTful API endpoints, see
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
