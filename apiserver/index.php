<?php

declare(strict_types=1);

/**
 * API test server to return queued responses to HTTP requests.
 *
 * The requests and responses can be enqueued via `/admin/*` endpoints.
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
 * This class is intended to be lightweight and limited in functionality.
 *
 * @phpcs:disable Drupal.Classes.ClassFileName.NoMatch
 */
class ApiServer {

  /**
   * The received requests.
   *
   * @var array<int|string, mixed>
   */
  protected array $requests = [];

  /**
   * The queued responses.
   *
   * @var array<mixed>
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
    // Use the unique per-server run ID as part of the state file name to ensure
    // unique state file for each server instance.
    $timestamp = getenv('PROCESS_TIMESTAMP') ?: getmypid();
    $this->stateFile = sys_get_temp_dir() . '/api_server_state.' . $timestamp . 'json';

    // Load state from the file if it exists.
    if (file_exists($this->stateFile)) {
      $contents = file_get_contents($this->stateFile);

      if ($contents === FALSE) {
        throw new \RuntimeException('Failed to read data from the server state file ' . $this->stateFile);
      }

      $state = json_decode($contents, TRUE);
      if ($state === NULL || !is_array($state)) {
        throw new \RuntimeException('Failed to load data from the server state file ' . $this->stateFile);
      }

      $this->requests = $state['requests'] ?? [];
      $this->responses = $state['responses'] ?? [];
    }
  }

  /**
   * Destructor to save the state to a file.
   */
  public function __destruct() {
    file_put_contents($this->stateFile, json_encode([
      'requests' => $this->requests,
      'responses' => $this->responses,
    ], JSON_PRETTY_PRINT));
  }

  /**
   * Handle the request.
   */
  public function handleRequest(): void {
    $request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $request_uri = is_scalar($_SERVER['REQUEST_URI']) ? strtok(strval($_SERVER['REQUEST_URI']), '?') : '/';
    $request_body = file_get_contents('php://input');
    $request_headers = getallheaders();

    $request_body = $request_body === FALSE ? '' : $request_body;

    if ($request_uri === '/admin/status') {
      $this->sendResponse(200, 'OK');
    }
    elseif ($request_uri === '/admin/requests' && $request_method === 'GET') {
      $response_body = json_encode($this->requests);
      if ($response_body === FALSE) {
        $this->sendErrorResponse('Failed to encode the requests to JSON');
      }
      $this->sendResponse(200, 'OK', [], $response_body);
    }
    elseif ($request_uri === '/admin/requests' && $request_method === 'DELETE') {
      $this->requests = [];
      $this->sendResponse(200, 'OK');
    }
    elseif ($request_uri === '/admin/responses' && $request_method === 'GET') {
      $response_body = json_encode($this->responses);
      if ($response_body === FALSE) {
        $this->sendErrorResponse('Failed to encode the responses to JSON');
      }
      $this->sendResponse(200, 'OK', [], $response_body);
    }
    elseif ($request_uri === '/admin/responses' && $request_method === 'DELETE') {
      $this->responses = [];
      $this->sendResponse(200, 'OK');
    }
    elseif ($request_uri === '/admin/responses' && $request_method === 'PUT') {
      $responses = json_decode($request_body, TRUE);
      if ($responses === NULL || !is_array($responses)) {
        $this->sendErrorResponse('Invalid responses JSON payload provided');
      }

      foreach ($responses as $k => &$response) {
        if (!is_array($response)) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Response must be an object.', $k + 1));
        }

        $response['method'] = $response['method'] ?? 'GET';

        if (!is_string($response['method'])) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Method must be a string.', $k + 1));
        }

        if (!in_array($response['method'], ['GET', 'POST', 'PUT', 'DELETE'])) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Unsupported HTTP method "%s". Supported methods are GET, POST, PUT, DELETE.', $k + 1, $response['method']));
        }

        if (empty($response['code'])) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Response code is required.', $k + 1));
        }

        $response['headers'] = $response['headers'] ?? [];
        if (!is_array($response['headers'])) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Headers must be an array.', $k + 1));
        }

        if (isset($response['body'])) {
          if (!is_string($response['body'])) {
            $this->sendErrorResponse(sprintf('Invalid response #%d payload: Body must be a string.', $k + 1));
          }

          $response_body = base64_decode($response['body']);
          // @phpstan-ignore-next-line
          if ($response_body === FALSE) {
            $this->sendErrorResponse(sprintf('Invalid response #%d payload: Body is not a valid base64 encoded string.', $k + 1));
          }

          $response['body'] = $response_body;
        }
        else {
          $response['body'] = '';
        }

        if (!empty($response['reason']) && !is_string($response['reason'])) {
          $this->sendErrorResponse(sprintf('Invalid response #%d payload: Reason must be a string.', $k + 1));
        }

        $response['reason'] = $response['reason'] ?: 'OK';
      }

      $this->responses = array_merge($this->responses, $responses);

      $this->sendResponse(201, 'Created');
    }
    else {
      $this->requests[] = [
        'method' => $request_method,
        'uri' => $request_uri,
        'headers' => $request_headers,
        'body' => $request_body,
      ];

      if (empty($this->responses)) {
        $this->sendErrorResponse('No responses in queue', 500, 'No responses in queue');
      }
      else {
        $response = array_shift($this->responses);

        if (
          !is_array($response) ||
          empty($response['code']) || !is_numeric($response['code']) || !is_int($response['code']) ||
          empty($response['reason']) || !is_string($response['reason']) ||
          !is_array($response['headers']) ||
          !is_string($response['body'])
        ) {
          $this->sendErrorResponse(sprintf('Invalid response in queue: %s', print_r($response, TRUE)), 500, 'Invalid response in queue');
        }

        $response['headers'] = array_map(fn($value): string => is_scalar($value) ? strval($value) : '', $response['headers']);

        $this->sendResponse($response['code'], $response['reason'], $response['headers'], $response['body']);
      }
    }
  }

  /**
   * Send the response.
   *
   * @param int $code
   *   The response code.
   * @param string $reason
   *   The response reason.
   * @param array<string,scalar> $headers
   *   The response headers.
   * @param string $body
   *   The response body.
   */
  protected function sendResponse(int $code, string $reason, array $headers = [], string $body = ''): void {
    // Set the full status line manually to include the custom reason.
    $protocol = is_scalar($_SERVER['SERVER_PROTOCOL']) ? strval($_SERVER['SERVER_PROTOCOL']) : 'HTTP/1.1';
    header(sprintf('%s %s %s', $protocol, $code, $reason));

    $headers += [
      'X-Received-Requests' => count($this->requests),
      'X-Queued-Responses' => count($this->responses),
    ];

    // Set Content-Length header if a body is provided.
    if ($body !== '') {
      $headers['Content-Length'] = strlen($body);
    }

    // Set additional headers.
    foreach ($headers as $key => $value) {
      header(sprintf('%s: %s', $key, $value));
    }

    print $body;
  }

  /**
   * Send an error response.
   */
  protected function sendErrorResponse(string $message, int $code = 400, string $reason = 'Bad Request'): never {
    $message = json_encode(['error' => $message]);

    if ($message === FALSE) {
      $message = 'An error occurred while encoding the error message.';
    }

    $this->sendResponse($code, $reason, [], $message);
    exit(1);
  }

}

$server = new ApiServer();
$server->handleRequest();
