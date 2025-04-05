<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer;

use Behat\Gherkin\Node\PyStringNode;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * Class ApiServerContext.
 *
 * Behat context to enable ApiServer support in tests.
 *
 * @see \DrevOps\BehatPhpServer\ApiServer
 *
 * @package DrevOps\BehatPhpServer
 */
class ApiServerContext extends PhpServerContext {

  /**
   * {@inheritdoc}
   */
  const TAG = 'apiserver';

  /**
   * {@inheritdoc}
   */
  const DEFAULT_WEBROOT = __DIR__ . '/../../../apiserver';

  /**
   * Default connect timeout in seconds.
   */
  const DEFAULT_CONNECT_TIMEOUT = 5;

  /**
   * Default request timeout in seconds.
   */
  const DEFAULT_REQUEST_TIMEOUT = 10;

  /**
   * Default read timeout in seconds.
   */
  const DEFAULT_READ_TIMEOUT = 10;

  /**
   * Guzzle HTTP client.
   */
  protected Client $client;

  /**
   * The fixtures directory paths.
   *
   * @var string[]
   */
  protected array $fixturesPaths = [];

  /**
   * Constructs the ApiServerContext.
   *
   * @param string|null $webroot
   *   The webroot to use.
   * @param string $host
   *   The host to use.
   * @param int $port
   *   The port to use.
   * @param string $protocol
   *   The protocol to use.
   * @param bool $debug
   *   Whether to enable debug mode.
   * @param int|null $connection_timeout
   *   The connection timeout.
   * @param int|null $retry_delay
   *   The retry delay.
   * @param string[]|string|null $paths
   *   An array of fixture paths or a single path string.
   */
  public function __construct(?string $webroot = NULL, string $host = '127.0.0.1', int $port = 8888, string $protocol = 'http', bool $debug = FALSE, ?int $connection_timeout = NULL, ?int $retry_delay = NULL, $paths = NULL) {
    parent::__construct($webroot, $host, $port, $protocol, $debug, $connection_timeout, $retry_delay);

    $this->client = $this->createHttpClient();

    // Set fixtures paths, with fallback to the default location.
    if (empty($paths)) {
      $this->fixturesPaths[] = dirname($this->webroot) . '/tests/behat/fixtures';
    }
    elseif (is_array($paths)) {
      // Handle both string and array.
      $this->fixturesPaths = array_map('strval', $paths);
    }
    else {
      $this->fixturesPaths[] = (string) $paths;
    }
  }

  /**
   * Check if the API server is running.
   *
   * @Given (the )API server is running
   */
  public function apiIsRunning(): void {
    // First check if server process is running.
    if (!$this->isRunning()) {
      $this->debug('API server process is not running. Attempting to start.');
      $this->start();
    }

    $response = $this->client->request('GET', '/admin/status');
    if ($response->getStatusCode() !== 200) {
      throw new \Exception('API server is not up');
    }
  }

  /**
   * Reset the API server by clearing all responses and requests.
   *
   * @Given (the )API server is reset
   */
  public function resetApi(): void {
    $this->client->request('DELETE', '/admin/responses');
    $this->client->request('DELETE', '/admin/requests');

    $this->debug('API server responses and requests have been reset.');
  }

  /**
   * Put expected response data to the API server.
   *
   * @Given (the )API will respond with:
   *
   * @code
   * Given API will respond with:
   * """
   * {
   *   "code": 200,
   *   "reason": "OK",
   *   "headers": {
   *     "Content-Type": "application/json"
   *   },
   *   "body": {
   *     "Id": "test-id-1",
   *     "Slug": "test-slug-1"
   *   }
   * }
   * """
   * @endcode
   */
  public function apiWillRespondWith(PyStringNode $data): void {
    $data = $this->prepareResponse($data->getRaw());

    $response = $this->client->request('PUT', '/admin/responses', [
      'json' => $data,
    ]);

    if ($response->getStatusCode() !== 201) {
      throw new \RuntimeException('Failed to set the API response.');
    }

    $this->debug('Successfully queued API response.');
  }

  /**
   * Put expected JSON response data to the API server.
   *
   * Shorthand for the API response with JSON body.
   *
   * @Given (the )API will respond with JSON:
   * @Given (the )API will respond with JSON and :code code:
   *
   * @code
   * Given API will respond with JSON:
   * """
   * {
   *   "Id": "test-id-1",
   *   "Slug": "test-slug-1"
   * }
   * """
   * @endcode
   *
   * @code
   * Given API will respond with JSON and 200 code:
   * """
   * {
   *   "Id": "test-id-1",
   *   "Slug": "test-slug-1"
   * }
   * """
   * @endcode
   */
  public function apiWillRespondWithJson(PyStringNode $json, ?string $code = NULL): void {
    $data = json_encode([
      'body' => json_decode($json->getRaw()),
      'code' => $code ?? 200,
    ]);

    $this->apiWillRespondWith(new PyStringNode([$data], $json->getLine()));
  }

  /**
   * Put expected file response data to the API server.
   *
   * @param string $file_path
   *   The path to the file, relative to the fixtures directory.
   * @param string|null $code
   *   The response code.
   *
   * @throws \RuntimeException
   *   If the file cannot be read.
   *
   * @Given (the )API will respond with file :file_path
   * @Given (the )API will respond with file :file_path and :code code
   *
   * @code
   * Given API will respond with file "test_data.json"
   * @endcode
   *
   * @code
   * Given API will respond with file "test_content.xml" and 201 code
   * @endcode
   */
  public function apiWillRespondWithFile(string $file_path, ?string $code = NULL): void {
    // Search for the file in all configured fixture paths.
    $file_found = FALSE;
    $absolute_path = '';
    $error_paths = [];

    foreach ($this->fixturesPaths as $fixtures_path) {
      $path = $fixtures_path . '/' . $file_path;
      $error_paths[] = $fixtures_path;

      if (file_exists($path)) {
        $absolute_path = $path;
        $file_found = TRUE;
        break;
      }
    }

    if (!$file_found) {
      throw new \RuntimeException(sprintf(
        'File "%s" does not exist in any of the configured fixture paths: %s',
        $file_path,
        implode(', ', $error_paths)
      ));
    }

    $content = file_get_contents($absolute_path);
    if ($content === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read file "%s".', $absolute_path));
    }

    // Determine content type based on file extension.
    $extension = pathinfo($file_path, PATHINFO_EXTENSION);
    $content_type = match (strtolower($extension)) {
      'json' => 'application/json',
      'xml' => 'application/xml',
      'html', 'htm' => 'text/html',
      'txt' => 'text/plain',
      default => 'application/octet-stream',
    };

    $data = json_encode([
      'body' => $content,
      'code' => $code ?? 200,
      'headers' => [
        'Content-Type' => $content_type,
      ],
    ]);

    $this->apiWillRespondWith(new PyStringNode([$data], 0));
  }

  /**
   * Process the response data.
   *
   * @param string $data
   *   The response data.
   *
   * @return array<int, array<string, mixed>>
   *   The response data.
   */
  protected function prepareResponse(string $data): array {
    $data = json_decode($data, TRUE);
    if ($data === NULL || !is_array($data)) {
      throw new \InvalidArgumentException('Request data is not a valid JSON.');
    }

    $data += [
      'code' => 200,
      // @todo Validate reason.
      'reason' => 'OK',
      'headers' => [],
      'body' => '',
    ];

    if (!is_numeric($data['code'])) {
      throw new \InvalidArgumentException('Status code must be a number.');
    }
    $data['code'] = intval($data['code']);

    if (!is_array($data['headers'])) {
      throw new \InvalidArgumentException('Headers must be an array.');
    }

    // Check that the headers are valid.
    foreach ($data['headers'] as $header_name => $header_value) {
      if (!is_string($header_name) || !is_string($header_value)) {
        throw new \InvalidArgumentException(sprintf('Header %s value must be a string.', $header_name));
      }
    }

    // Convert the body to a JSON string as it would be in a real response.
    if (isset($data['body']) || $data['body'] !== NULL) {
      if (is_array($data['body'])) {
        $data['body'] = json_encode($data['body']);
      }

      $data['body'] = \base64_encode($data['body']);
    }

    return [$data];
  }

  /**
   * Create a configured HTTP client with proper timeouts and retry handling.
   *
   * @param array<string, mixed> $options
   *   Additional client options to merge with defaults.
   *
   * @return \GuzzleHttp\Client
   *   Configured Guzzle client.
   */
  protected function createHttpClient(array $options = []): Client {
    $defaults = [
      'base_uri' => $this->getServerUrl(),
      'http_errors' => FALSE,
      RequestOptions::CONNECT_TIMEOUT => static::DEFAULT_CONNECT_TIMEOUT,
      RequestOptions::TIMEOUT => static::DEFAULT_REQUEST_TIMEOUT,
      RequestOptions::READ_TIMEOUT => static::DEFAULT_READ_TIMEOUT,
    ];

    return new Client(array_merge($defaults, $options));
  }

}
