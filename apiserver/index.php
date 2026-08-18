<?php

/**
 * @file
 * API test server to return queued responses to HTTP requests.
 *
 * Responses can be enqueued via the `/admin/*` endpoints. Requests are
 * recorded automatically as they arrive.
 *
 * Supported endpoints:
 * - GET `/admin/status`: Check the server status.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 0
 *
 * - GET `/admin/requests`: Get the received requests.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 1
 *   > X-Queued-Responses: 0
 *   > Content-Type: application/json
 *   > [{'http_method': 'GET', 'uri': '/', 'headers': {}, 'body': 'string'}]
 *
 * - DELETE `/admin/requests`: Delete all received requests.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 0
 *
 * - GET `/admin/responses`: Get the queued responses.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 1
 *   > Content-Type: application/json
 *   > [{'code': 200, 'reason': 'OK', 'headers': {}, 'body': '' }]
 *
 * - DELETE `/admin/responses`: Delete all queued responses.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 0
 *
 * - PUT `/admin/responses`: Enqueue responses.
 *   > HTTP/1.1 201 Created
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 1
 *   > Content-Type: application/json
 *   > [{'code': 200, 'reason': 'OK', 'headers': {}, 'body': '' }, {'code': 404, 'reason': 'Not found', 'headers': {}, 'body': '' }]
 *
 * This file is intended to be lightweight and portable.
 *
 * @phpcs:disable Drupal.Classes.ClassFileName.NoMatch
 * @phpcs:disable Drupal.Commenting.ClassComment.Missing
 */

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\ApiServer;

class ApiServer {

  /**
   * The received requests.
   *
   * @var array<int, Request>
   */
  protected array $requests = [];

  /**
   * The queued responses.
   *
   * @var array<int, Response>
   */
  protected array $responses = [];

  /**
   * The state file to store the server state.
   */
  protected string $stateFile;

  /**
   * ApiServer constructor.
   */
  public function __construct() {
    // Include the unique per-server run ID in the state file name so each
    // server instance has its own state file.
    $timestamp = getenv('PROCESS_TIMESTAMP') ?: getmypid();
    $this->stateFile = sys_get_temp_dir() . '/api_server_state.' . $timestamp . '.ser';

    if (file_exists($this->stateFile)) {
      $contents = file_get_contents($this->stateFile);

      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Failed to read data from the server state file %s', $this->stateFile));
      }

      // Restrict deserialisation to the 2 value objects the state can hold, so
      // a tampered state file cannot instantiate anything else or reach its
      // magic methods.
      $state = unserialize($contents, ['allowed_classes' => [Request::class, Response::class]]);
      if (!is_array($state)) {
        throw new \RuntimeException(sprintf('Failed to load data from the server state file %s', $this->stateFile));
      }

      // The state file is untrusted input, so keep only the entries that
      // survived deserialisation as the objects they claim to be.
      $requests = $state['requests'] ?? [];
      $responses = $state['responses'] ?? [];

      $this->requests = is_array($requests) ? array_values(array_filter($requests, static fn(mixed $item): bool => $item instanceof Request)) : [];
      $this->responses = is_array($responses) ? array_values(array_filter($responses, static fn(mixed $item): bool => $item instanceof Response)) : [];
    }
  }

  /**
   * Destructor to save the state to a file.
   */
  public function __destruct() {
    $state = serialize([
      'requests' => $this->requests,
      'responses' => $this->responses,
    ]);

    file_put_contents($this->stateFile, $state);
  }

  /**
   * Handle the request.
   */
  public function handleRequest(): void {
    $request = new Request(
      isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
      isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI']) ? (string) strtok((string) $_SERVER['REQUEST_URI'], '?') : '/',
      getallheaders(),
      file_get_contents('php://input') ?: ''
    );

    if ($request->uri === '/admin/status') {
      $this->handleResponse(new Response(200, 'OK'));
    }
    elseif ($request->uri === '/admin/requests' && $request->method === 'GET') {
      $this->handleResponse(new Response(200, 'OK', [], $this->requests));
    }
    elseif ($request->uri === '/admin/requests' && $request->method === 'DELETE') {
      $this->requests = [];
      $this->handleResponse(new Response(200, 'OK'));
    }
    elseif ($request->uri === '/admin/responses' && $request->method === 'GET') {
      $this->handleResponse(new Response(200, 'OK', [], $this->responses));
    }
    elseif ($request->uri === '/admin/responses' && $request->method === 'DELETE') {
      $this->responses = [];
      $this->handleResponse(new Response(200, 'OK'));
    }
    elseif ($request->uri === '/admin/responses' && $request->method === 'PUT') {
      $responses_data = json_decode($request->body, TRUE);
      if ($responses_data === NULL || !is_array($responses_data)) {
        throw new \InvalidArgumentException('Invalid responses JSON payload provided: Expected an array of response objects.', 400);
      }

      foreach ($responses_data as $k => $response_data) {
        if (!is_array($response_data)) {
          throw new \InvalidArgumentException(sprintf('Invalid response #%d payload: Response must be an object.', $k + 1), 400);
        }

        try {
          $response = Response::fromArray($response_data);
        }
        catch (\InvalidArgumentException $exception) {
          throw new \InvalidArgumentException(sprintf('Invalid response #%d payload: %s', $k + 1, $exception->getMessage()), 400, $exception);
        }

        $this->responses[] = $response;
      }

      $this->handleResponse(new Response(201, 'Created'));
    }
    else {
      $this->requests[] = $request;

      if (empty($this->responses)) {
        throw new \Exception('No responses in queue', 500);
      }

      $response = array_shift($this->responses);

      $this->handleResponse($response);
    }
  }

  /**
   * Send the response.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\Response $response
   *   The response object.
   */
  protected function handleResponse(Response $response): void {
    $response->headers += [
      'X-Received-Requests' => (string) count($this->requests),
      'X-Queued-Responses' => (string) count($this->responses),
    ];

    static::sendResponse($response);
  }

  /**
   * Send the response.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\Response $response
   *   The response object.
   */
  public static function sendResponse(Response $response): void {
    // Set the full status line manually to include the custom reason.
    $protocol = isset($_SERVER['SERVER_PROTOCOL']) && is_scalar($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    header(sprintf('%s %s %s', $protocol, $response->code, $response->reason));

    foreach ($response->headers as $header_name => $header_value) {
      header(sprintf('%s: %s', $header_name, $header_value));
    }

    print $response->body;
  }

}

class Request {

  final public function __construct(
    public string $method = 'GET',
    public string $uri = '/',
    /**
     * Headers.
     *
     * @var array<string,string>
     */
    public array $headers = [],
    public string $body = '',
  ) {
  }

}

class Response {

  /**
   * Response body.
   */
  public string $body;

  final public function __construct(
    public int $code = 200,
    public string $reason = 'OK',
    /**
     * Headers.
     *
     * @var array<string,string>
     */
    public array $headers = [],
    mixed $body = '',
  ) {
    if (is_scalar($body)) {
      $this->body = (string) $body;
      if (static::isJson($this->body)) {
        $this->headers['Content-Type'] = 'application/json';
      }
    }
    else {
      $this->body = (string) json_encode($body);
      $this->headers['Content-Type'] = 'application/json';
    }

    if ($this->body !== '') {
      $this->headers['Content-Length'] = (string) strlen($this->body);
    }
  }

  /**
   * Create a response from an array.
   *
   * @param array<mixed,mixed> $data
   *   The response data.
   *
   * @return static
   *   The response object.
   */
  public static function fromArray(array $data): static {
    $data += [
      'method' => 'GET',
      'code' => 200,
      'reason' => 'OK',
      'headers' => [],
      'body' => '',
    ];

    if (!is_string($data['method'])) {
      throw new \InvalidArgumentException('Method must be a string.');
    }

    if (!in_array($data['method'], ['GET', 'POST', 'PUT', 'DELETE'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s". Supported methods are GET, POST, PUT, DELETE.', $data['method']));
    }

    if (empty($data['code'])) {
      throw new \InvalidArgumentException('Response code is required.');
    }

    $data['code'] = is_numeric($data['code']) ? (int) $data['code'] : 0;

    if ($data['code'] < 100 || $data['code'] > 599) {
      throw new \InvalidArgumentException('Response code must be a number between 100 and 599.');
    }

    $data['headers'] ??= [];
    if (!is_array($data['headers'])) {
      throw new \InvalidArgumentException('Headers must be an array.');
    }

    // Require string keys and scalar values, cast to string below.
    $headers = [];
    foreach ($data['headers'] as $header_name => $header_value) {
      if (!is_string($header_name) || !is_scalar($header_value)) {
        throw new \InvalidArgumentException(sprintf('Header "%s" value must be a string.', $header_name));
      }
      $headers[$header_name] = (string) $header_value;
    }
    $data['headers'] = $headers;

    if (isset($data['body'])) {
      if (!is_string($data['body'])) {
        throw new \InvalidArgumentException('Body must be a string.');
      }

      $data['body'] = base64_decode($data['body']);
    }

    if (empty($data['reason']) || !is_string($data['reason'])) {
      throw new \InvalidArgumentException('Reason must be a string.');
    }

    return new static($data['code'], $data['reason'], $data['headers'], $data['body']);
  }

  /**
   * Check if the string is a JSON.
   *
   * @param string $string
   *   The string to check.
   *
   * @return bool
   *   TRUE if the string is a JSON, FALSE otherwise.
   */
  protected static function isJson(string $string): bool {
    return json_decode($string) !== NULL || json_last_error() === JSON_ERROR_NONE;
  }

}

// Allow skipping the script run.
if (getenv('SCRIPT_RUN_SKIP') !== '1') {
  $server = new ApiServer();

  try {
    $server->handleRequest();
  }
  catch (\Throwable $throwable) {
    ApiServer::sendResponse(new Response($throwable->getCode(), $throwable->getMessage(), [], ['error' => $throwable->getMessage()]));
  }
}
