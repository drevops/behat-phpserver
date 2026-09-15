<div align="center">
  <a href="https://github.com/drevops/behat-phpserver" rel="noopener">
  <img width=200px height=200px src="https://placehold.jp/000000/ffffff/200x200.png?text=Behat+PHP+server&css=%7B%22border-radius%22%3A%22%20100px%22%7D" alt="Behat PHP server logo"></a>
</div>

<h1 align="center">PHP and API server for Behat tests</h1>
<div align="center">

[![GitHub Issues](https://img.shields.io/github/issues/drevops/behat-phpserver.svg)](https://github.com/drevops/behat-phpserver/issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/drevops/behat-phpserver.svg)](https://github.com/drevops/behat-phpserver/pulls)
[![Test PHP](https://github.com/drevops/behat-phpserver/actions/workflows/test-php.yml/badge.svg)](https://github.com/drevops/behat-phpserver/actions/workflows/test-php.yml)
[![codecov](https://codecov.io/gh/drevops/behat-phpserver/branch/main/graph/badge.svg?token=KZCCZXN5C4)](https://codecov.io/gh/drevops/behat-phpserver)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/drevops/behat-phpserver)
[![Total Downloads](https://poser.pugx.org/drevops/behat-phpserver/downloads)](https://packagist.org/packages/drevops/behat-phpserver)
![LICENSE](https://img.shields.io/github/license/drevops/behat-phpserver)
![Renovate](https://img.shields.io/badge/renovate-enabled-green?logo=renovatebot)

[![Vortex Ecosystem](https://img.shields.io/badge/%F0%9F%8C%80-Vortex%20Ecosystem-2C5A68?style=for-the-badge&labelColor=65ACBC)](https://github.com/drevops/vortex)
</div>

## ✨ Features

- [`PhpServerContext`](src/DrevOps/BehatPhpServer/PhpServerContext.php) - starts and stops PHP's built-in web server around each scenario:
  - Serves files from a configurable document root.
  - Configurable protocol, host and port.
  - Tag a scenario or a whole feature with `@phpserver` to opt in.
- [`ApiServerContext`](src/DrevOps/BehatPhpServer/ApiServerContext.php) - runs a mock [API server](apiserver/index.php) that replays queued responses:
  - Step definitions to queue responses inline, as JSON, or from a fixture file.
  - Step definitions to assert how many requests arrived and how many responses are still queued.
  - Records every received request for debugging.
  - Tag a scenario or a whole feature with `@apiserver` to opt in.

## 📦 Installation

| Behat        | PHP     | Guzzle            | Configuration file                                             |
|--------------|---------|-------------------|----------------------------------------------------------------|
| `^3.32.0`    | `>=8.3` | `^7.15.3 \|\| ^8` | `behat.yml`, `behat.dist.yml`, `behat.php` or `behat.dist.php` |
| `^4.0@alpha` | `>=8.3` | `^7.15.3 \|\| ^8` | `behat.php` or `behat.dist.php`                                |

    composer require --dev drevops/behat-phpserver

Coming from 2.x? See [`UPGRADE.md`](UPGRADE.md) - the step phrases are unchanged, but some method names moved.

## 🚀 Usage

### `PhpServerContext`

Serves static assets from a pre-defined document root.

```yaml
default:
  suites:
    default:
      contexts:
        - DrevOps\BehatPhpServer\PhpServerContext:
            webroot: '%paths.base%/tests/behat/fixtures'
            protocol: http
            host: 0.0.0.0
            port: 8888
            debug: false
```

This context adds no step definitions. It starts the server before a tagged scenario and stops it afterwards, so tag the scenarios that need it:

```gherkin
@phpserver
Scenario: Visit a page served by the PHP server
  ...
```

Tagging the `Feature:` line instead starts the server for every scenario in that feature.

Reach the running server through `getServerUrl()` - see [Accessing the server URL from your own context](#accessing-the-server-url-from-your-own-context).

### `ApiServerContext`

Serves pre-set API responses. It extends `PhpServerContext`, so it accepts the same options plus `paths`.

```yaml
default:
  suites:
    default:
      contexts:
        - DrevOps\BehatPhpServer\ApiServerContext:
            protocol: http
            host: 0.0.0.0
            port: 8889
            debug: false
            paths:
              - '%paths.base%/tests/behat/fixtures'
              - '%paths.base%/tests/behat/fixtures2'
```

### PHP configuration

Behat 4 reads its configuration from `behat.php`, or from `behat.dist.php` when there's no `behat.php`. Here are both examples above as PHP:

```php
<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;

$suite = (new Suite('default'))
  ->addContext(PhpServerContext::class, [
    'webroot' => '%paths.base%/tests/behat/fixtures',
    'protocol' => 'http',
    'host' => '0.0.0.0',
    'port' => 8888,
    'debug' => FALSE,
  ])
  ->addContext(ApiServerContext::class, [
    'protocol' => 'http',
    'host' => '0.0.0.0',
    'port' => 8889,
    'debug' => FALSE,
    'paths' => [
      '%paths.base%/tests/behat/fixtures',
      '%paths.base%/tests/behat/fixtures2',
    ],
  ]);

$profile = (new Profile('default'))->withSuite($suite);

return (new Config())->withProfile($profile);
```

The option names are the same in both formats, so the table below covers either one.

### Context options

| Option               | Default                      | Description                                                                 |
|----------------------|------------------------------|-----------------------------------------------------------------------------|
| `webroot`            | See below                    | Document root the server serves from. Must exist, or the constructor throws. |
| `host`               | `127.0.0.1`                  | Server host.                                                                |
| `port`               | `8888`                       | Server port.                                                                |
| `protocol`           | `http`                       | Server protocol, used to build the server URL.                              |
| `debug`              | `false`                      | Print verbose output about server start, stop and connection attempts.      |
| `connection_timeout` | `2`                          | Seconds to keep retrying a connection before the server is declared failed.  |
| `retry_delay`        | `100000`                     | Microseconds to wait between connection retries.                            |
| `paths`              | None                         | `ApiServerContext` only. One path or a list of paths searched, in order, for file responses. File responses need at least one. |

`PhpServerContext` requires `webroot`. `ApiServerContext` defaults it to the bundled `apiserver` directory.

Both contexts default to port `8888`. When both are registered, give each one its own port, as shown above.

[`behat.dist.yml`](behat.dist.yml) and [`behat.dist.php`](behat.dist.php) set every option for both contexts.

## 📖 Step definitions

### Server lifecycle

```gherkin
# Start the API server if it is not already running.
Given the API server is running

# Clear all queued responses and all recorded requests.
Given the API server is reset

# Clear only the queued responses, leaving recorded requests intact.
Given the API has no responses
```

### Queueing responses

```gherkin
# Queue a response with full control over code, reason, headers and body.
Given API will respond with:
  """
  {
    "code": 200,
    "reason": "OK",
    "headers": {
      "Content-Type": "application/json"
    },
    "body": {
      "Id": "test-id-1",
      "Slug": "test-slug-1"
    }
  }
  """

# Every field may be omitted: "code" defaults to 200, "reason" to "OK",
# "headers" to none and "body" to empty.
Given API will respond with:
  """
  {
    "code": 201
  }
  """

# Queue a JSON body, defaulting to a 200 response.
Given API will respond with JSON:
  """
  {
    "Id": "test-id-1",
    "Slug": "test-slug-1"
  }
  """

# Queue a JSON body with an explicit response code.
Given API will respond with JSON and 201 code:
  """
  {
    "Id": "test-id-2",
    "Slug": "test-slug-2"
  }
  """

# Queue the contents of a fixture file, with the content type detected
# from its extension.
Given API will respond with file "test_data.json"

# Queue a fixture file with an explicit response code.
Given API will respond with file "test_content.xml" and 201 code
```

Responses are replayed in the order they were queued, one per request.

### Assertions and debugging

```gherkin
# Assert how many requests the server received.
Then the API server should have 3 received requests

# Assert how many responses are still waiting to be replayed.
Then the API server should have 0 queued responses

# Print every recorded request to stdout.
When I debug API requests
```

Both assertion steps also accept the alternative phrasings `the API server should have received 3 requests` and `the API server should have 0 responses queued`, and both accept a singular noun for a count of one.

See the [test feature](tests/behat/features/apiserver.feature) for worked examples of every step.

### File responses

`API will respond with file` reads a file from the configured `paths`, searching each path in the order given until it finds a match. The content type is derived from the file extension:

| Extension        | `Content-Type`             |
|------------------|----------------------------|
| `.json`          | `application/json`         |
| `.xml`           | `application/xml`          |
| `.html`, `.htm`  | `text/html`                |
| `.txt`           | `text/plain`               |
| anything else    | `application/octet-stream` |

### Accessing the server URL from your own context

To point an API client at the running server, read the URL in a `#[BeforeScenario]` hook:

```php
<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
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
   */
  #[BeforeScenario]
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

## 🔌 API server HTTP endpoints

The step definitions cover the common cases. The mock server also exposes the endpoints directly, which is useful when driving it from code rather than from Gherkin.

| Method   | Endpoint           | Result                                                          |
|----------|--------------------|-----------------------------------------------------------------|
| `GET`    | `/admin/status`    | `200 OK`. Reports the counts in the headers below.              |
| `GET`    | `/admin/requests`  | `200 OK` with the recorded requests as JSON.                     |
| `DELETE` | `/admin/requests`  | `200 OK`. Clears the recorded requests.                          |
| `GET`    | `/admin/responses` | `200 OK` with the queued responses as JSON.                      |
| `DELETE` | `/admin/responses` | `200 OK`. Clears the queued responses.                           |
| `PUT`    | `/admin/responses` | `201 Created`. Appends the posted responses to the queue.        |

Any other method on one of these endpoints is refused with `405 Method Not Allowed` and an `Allow` header listing the methods it accepts. A refused request is not recorded and the response queue is left untouched.

These endpoints and the replayed responses carry an `X-Received-Requests` and an `X-Queued-Responses` header with the current counts. Error responses do not.

Any other request is recorded and answered with the next queued response. When the queue is empty, the server answers `500` with `No responses in queue`.

An error response carries its message in the status line and in a JSON body, such as `{"error":"No responses in queue"}`.

`PUT /admin/responses` takes an array of response objects:

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
    "headers": {},
    "body": ""
  }
]
```

`body` must be **base64-encoded** - the server decodes it before replaying the response. The step definitions do this encoding for you, so it only matters when calling the endpoint directly. `code` must be between 100 and 599, `reason` must be a non-empty string, and `headers` must be an object with scalar values. A payload that breaks these rules is refused with `400`, and none of its responses are queued.

## 🤝 Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for local setup, linting, testing and maintenance.

---
_This repository was created using the [Scaffold](https://getscaffold.dev/) project template_
