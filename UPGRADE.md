# Upgrade guide

## 2.x to 3.0

**Your `.feature` files don't need to change.** None of the Gherkin step phrases moved, and neither did any of the `behat.yml` option keys. Most of what follows is about PHP-level names, so it only affects you if you call the context methods from your own code or subclass a context. The exception is the admin endpoint change below, which affects code that calls those endpoints directly.

### PHP 8.3 or newer is required

The minimum supported PHP version is now 8.3.

### Guzzle 8 is supported alongside Guzzle 7

`guzzlehttp/guzzle` is now `^7.15.3 || ^8`. Guzzle 8 is a stable release, so the next full `composer update` installs it, unless another package in your project requires Guzzle 7, `guzzlehttp/psr7` 2.x or `guzzlehttp/promises` 2.x.

That only matters if your own code uses the Guzzle client that `ApiServerContext` builds, through its `$client` property or an override of `createHttpClient()`. Guzzle 8 tightens parts of the client API that code might rely on. For example, passing `handler` as a request option now throws, and verbs without their own method, such as `$client->options()`, can no longer be called as methods. Check that code against the [Guzzle 8 upgrade guide](https://github.com/guzzle/guzzle/blob/8.2.0/UPGRADING.md#70-to-80), or require `guzzlehttp/guzzle:^7` in your own `composer.json` to stay on Guzzle 7.

### Admin endpoints check the HTTP method

`/admin/status` matched on the URI alone, so it answered `200 OK` to every verb. It now accepts `GET` only.

An unexpected method on any admin endpoint is refused with `405 Method Not Allowed` and an `Allow` header:

| Endpoint           | Allowed methods        |
|--------------------|------------------------|
| `/admin/status`    | `GET`                  |
| `/admin/requests`  | `GET`, `DELETE`        |
| `/admin/responses` | `GET`, `PUT`, `DELETE` |

An unexpected method on `/admin/requests` or `/admin/responses` used to fall through to the queue, where it was recorded as a received request and consumed a queued response. A refused request now leaves both untouched.

The step definitions have always used the supported methods, so this only affects code that calls the endpoints directly.

### Step methods on `ApiServerContext` were renamed

The class spelled the same idea 4 different ways: an `api` prefix on 5 methods, an `Api` suffix on 1, an `Api` infix on 1, and an `assert` prefix on 2. They all now follow one rule - the method name is the step phrase in camelCase, with the `API` token folded into a leading `api` prefix.

| Old method                      | New method                       | Step phrase (unchanged)                                  |
|---------------------------------|----------------------------------|----------------------------------------------------------|
| `resetApi()`                    | `apiIsReset()`                   | `the API server is reset`                                 |
| `debugApiRequests()`            | `apiDebugRequests()`             | `I debug API requests`                                    |
| `assertQueuedResponsesCount()`  | `apiShouldHaveQueuedResponses()` | `the API server should have :count queued response(s)`    |
| `assertReceivedRequestsCount()` | `apiShouldHaveReceivedRequests()`| `the API server should have :count received request(s)`   |

The other 5 step methods - `apiIsRunning()`, `apiHasNoResponses()`, `apiWillRespondWith()`, `apiWillRespondWithJson()` and `apiWillRespondWithFile()` - already followed the rule and kept their names.

There are no aliases for the old names. If you called or overrode one of the 4, rename it.

### `ApiServerContext::__construct()` now types `$paths`

The `$paths` parameter was the only untyped parameter in the library. It's now `array|string|null`, matching the `string[]|string|null` its docblock always claimed.

The parameter name is unchanged, so the `paths:` key in `behat.yml` keeps working. What changes is that a value PHP used to coerce is now a `TypeError`. In practice that means an unquoted number:

```yaml
# Fails on 3.0 - YAML reads this as an integer.
paths: 8888

# Fine.
paths: '8888'
```

Elements *inside* a `paths` list are still cast to string, so a list with an unquoted number in it keeps working.

### `PhpServerContext::debug()` is now `printDebug()`

`PhpServerContext` had a `$debug` constructor option and a `debug()` method sitting next to each other, and `$this->debug` versus `$this->debug()` is one character apart. The method is now `printDebug()`.

The `debug` option in `behat.yml` is unchanged - only the method moved. Rename any call or override in your own contexts:

```php
// Before.
$this->debug('Server started.');

// After.
$this->printDebug('Server started.');
```
