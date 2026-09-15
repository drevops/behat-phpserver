<?php

/**
 * @file
 * API test server to return queued responses to HTTP requests.
 *
 * Responses are enqueued through `PUT /admin/responses`. A request to any
 * path other than the admin endpoints below is recorded and answered with
 * the next queued response.
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
 *   > [{'method': 'GET', 'uri': '/', 'headers': {}, 'body': 'string'}]
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
 *   > [{'code': 200, 'reason': 'OK', 'headers': {}, 'body': ''}]
 *
 * - DELETE `/admin/responses`: Delete all queued responses.
 *   > HTTP/1.1 200 OK
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 0
 *
 * - PUT `/admin/responses`: Enqueue the responses in the JSON array sent as
 *   the request body, such as
 *   [{'code': 200, 'reason': 'OK', 'headers': {}, 'body': ''}].
 *   > HTTP/1.1 201 Created
 *   > X-Received-Requests: 0
 *   > X-Queued-Responses: 1
 *
 * Any other method on one of these endpoints is refused with `405 Method Not
 * Allowed` and an `Allow` header listing the methods it accepts. A refused
 * request is not recorded.
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
   * The methods each admin endpoint accepts.
   */
  const ADMIN_METHODS = [
    '/admin/status' => ['GET'],
    '/admin/requests' => ['GET', 'DELETE'],
    '/admin/responses' => ['GET', 'PUT', 'DELETE'],
  ];

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
  final public function __construct() {
    // The per-run ID in the file name keeps each server run's state separate.
    $timestamp = getenv('PROCESS_TIMESTAMP') ?: getmypid();
    $this->stateFile = sys_get_temp_dir() . '/api_server_state.' . $timestamp . '.ser';

    if (!file_exists($this->stateFile)) {
      return;
    }

    $state = $this->loadState();

    // The state file is untrusted input, so keep only the entries whose
    // class matches their collection.
    $requests = $state['requests'] ?? [];
    $responses = $state['responses'] ?? [];

    $this->requests = is_array($requests) ? array_values(array_filter($requests, static fn(mixed $item): bool => $item instanceof Request)) : [];
    $this->responses = is_array($responses) ? array_values(array_filter($responses, static fn(mixed $item): bool => $item instanceof Response)) : [];
  }

  /**
   * Read the persisted state.
   *
   * @return array<mixed, mixed>
   *   The persisted state.
   */
  protected function loadState(): array {
    // Reading and deserialising both warn on failure, and a warning prints
    // into the response body when display_errors is on. The warning is
    // collected here and reported through the exception instead.
    $warning = '';
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
      $warning = $message;

      return TRUE;
    });

    try {
      $contents = file_get_contents($this->stateFile);

      if ($contents === FALSE) {
        throw new \RuntimeException(rtrim(sprintf('Failed to read data from the server state file %s. %s', $this->stateFile, $warning)), 500);
      }

      // The state holds only Request and Response objects. With
      // allowed_classes limited to them, unserialize() instantiates no other
      // class from a tampered file and runs no other class's magic methods.
      $state = unserialize($contents, ['allowed_classes' => [Request::class, Response::class]]);

      if (!is_array($state)) {
        throw new \RuntimeException(rtrim(sprintf('Failed to load data from the server state file %s. %s', $this->stateFile, $warning)), 500);
      }

      return $state;
    }
    finally {
      restore_error_handler();
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
   * Serve the current request, reporting any failure as a response.
   */
  public static function run(): void {
    try {
      // The constructor reads the state file, so it belongs inside the block
      // that turns a failure into a response.
      $server = new static();
      $server->handleRequest();
    }
    catch (\Throwable $throwable) {
      static::sendResponse(static::errorResponse($throwable));
    }
  }

  /**
   * Build the request from the incoming HTTP request.
   *
   * @return \DrevOps\BehatPhpServer\ApiServer\Request
   *   The request object.
   */
  protected function createRequest(): Request {
    return new Request(
      isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
      isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI']) ? (string) strtok((string) $_SERVER['REQUEST_URI'], '?') : '/',
      getallheaders(),
      file_get_contents('php://input') ?: ''
    );
  }

  /**
   * Handle the request.
   */
  public function handleRequest(): void {
    $request = $this->createRequest();

    $allowed_methods = static::ADMIN_METHODS[$request->uri] ?? NULL;

    if ($allowed_methods !== NULL && !in_array($request->method, $allowed_methods, TRUE)) {
      // Error responses carry no counts headers, so this bypasses
      // handleResponse().
      static::sendResponse(static::methodNotAllowedResponse($request, $allowed_methods));

      return;
    }

    if ($request->uri === '/admin/status' && $request->method === 'GET') {
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
      // An associative decode converts JSON objects to arrays, so objects are
      // decoded as stdClass to tell them apart.
      $responses_data = json_decode($request->body);

      if (!is_array($responses_data) || !array_is_list($responses_data)) {
        throw new \InvalidArgumentException('Invalid responses JSON payload provided: Expected an array of response objects.', 400);
      }

      $responses = [];

      foreach ($responses_data as $k => $response_data) {
        if (!$response_data instanceof \stdClass) {
          throw new \InvalidArgumentException(sprintf('Invalid response #%d payload: Response must be an object.', $k + 1), 400);
        }

        $response_fields = get_object_vars($response_data);

        if (($response_fields['headers'] ?? NULL) instanceof \stdClass) {
          $response_fields['headers'] = get_object_vars($response_fields['headers']);
        }

        try {
          $responses[] = Response::fromArray($response_fields);
        }
        catch (\InvalidArgumentException $exception) {
          throw new \InvalidArgumentException(sprintf('Invalid response #%d payload: %s', $k + 1, $exception->getMessage()), 400, $exception);
        }
      }

      // Every response is validated before any is queued, so a refused payload
      // leaves the queue untouched.
      $this->responses = array_merge($this->responses, $responses);

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
   * Send the response with the request and queue counts in its headers.
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
    // The full status line is set manually so the custom reason is included.
    $protocol = isset($_SERVER['SERVER_PROTOCOL']) && is_scalar($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    header(sprintf('%s %s %s', $protocol, $response->code, $response->reason));

    foreach ($response->headers as $header_name => $header_value) {
      header(sprintf('%s: %s', $header_name, $header_value));
    }

    print $response->body;
  }

  /**
   * Build the response that reports a failure.
   *
   * @param \Throwable $throwable
   *   The failure to report.
   *
   * @return \DrevOps\BehatPhpServer\ApiServer\Response
   *   The response object.
   */
  protected static function errorResponse(\Throwable $throwable): Response {
    $message = $throwable->getMessage();

    // A throwable carrying no code reports 0, which is not a status.
    $code = $throwable->getCode();

    if ($code < 100 || $code > 599) {
      $code = 500;
    }

    // The reason is written into the status line, which holds a single line
    // of text. The body carries the message as it was thrown.
    $reason = trim(preg_replace('/[[:cntrl:]\s]+/', ' ', $message) ?? '');

    return new Response($code, $reason === '' ? 'Unknown error' : $reason, [], ['error' => $message]);
  }

  /**
   * Build the response that refuses a method on a known admin endpoint.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\Request $request
   *   The refused request.
   * @param array<int, string> $allowed_methods
   *   The methods the endpoint accepts.
   *
   * @return \DrevOps\BehatPhpServer\ApiServer\Response
   *   The response object.
   */
  protected static function methodNotAllowedResponse(Request $request, array $allowed_methods): Response {
    $allowed = implode(', ', $allowed_methods);
    $message = sprintf('Method %s is not allowed on %s. Allowed methods: %s.', $request->method, $request->uri, $allowed);

    return new Response(405, 'Method Not Allowed', ['Allow' => $allowed], ['error' => $message]);
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
      $is_json = static::isJson($this->body);
    }
    else {
      // $body may hold bytes that are not valid UTF-8. json_encode() returns
      // FALSE for invalid UTF-8, and FALSE casts to an empty body.
      $this->body = (string) json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
      $is_json = TRUE;
    }

    if ($is_json && !$this->hasHeader('Content-Type')) {
      $this->headers['Content-Type'] = 'application/json';
    }

    if ($this->body !== '') {
      $this->headers['Content-Length'] = (string) strlen($this->body);
    }
  }

  /**
   * Check whether a header is set, ignoring the case of its name.
   *
   * @param string $name
   *   The header name.
   *
   * @return bool
   *   TRUE if the header is set, FALSE otherwise.
   */
  protected function hasHeader(string $name): bool {
    $header_names = array_map(strtolower(...), array_keys($this->headers));

    return in_array(strtolower($name), $header_names, TRUE);
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
      'code' => 200,
      'reason' => 'OK',
      'headers' => [],
      'body' => '',
    ];

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

    if (!is_string($data['reason']) || $data['reason'] === '') {
      throw new \InvalidArgumentException('Reason must be a non-empty string.');
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

if (getenv('SCRIPT_RUN_SKIP') !== '1') {
  ApiServer::run();
}
