<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use DrevOps\BehatPhpServer\ApiServer\ApiServer;
use DrevOps\BehatPhpServer\ApiServer\Request;
use DrevOps\BehatPhpServer\ApiServer\Response;
use DrevOps\BehatPhpServer\Tests\Traits\ReflectionTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiServer::class)]
#[CoversClass(Request::class)]
class ApiServerTest extends TestCase {

  use ReflectionTrait;

  /**
   * The state file of the server under test.
   */
  protected string $stateFile;

  /**
   * The PROCESS_TIMESTAMP value from before the test, or NULL when unset.
   */
  protected ?string $originalTimestamp;

  protected function setUp(): void {
    parent::setUp();

    $original_timestamp = getenv('PROCESS_TIMESTAMP');
    $this->originalTimestamp = $original_timestamp === FALSE ? NULL : $original_timestamp;

    // The server names its state file after PROCESS_TIMESTAMP, so pinning the
    // variable gives each test a state file of its own.
    $timestamp = uniqid('test', TRUE);
    putenv('PROCESS_TIMESTAMP=' . $timestamp);

    $this->stateFile = sys_get_temp_dir() . '/api_server_state.' . $timestamp . '.ser';
  }

  protected function tearDown(): void {
    if ($this->originalTimestamp === NULL) {
      putenv('PROCESS_TIMESTAMP');
    }
    else {
      putenv('PROCESS_TIMESTAMP=' . $this->originalTimestamp);
    }

    if (file_exists($this->stateFile)) {
      unlink($this->stateFile);
    }

    parent::tearDown();
  }

  public function testRequestDefaults(): void {
    $request = new Request();

    $this->assertEquals('GET', $request->method);
    $this->assertEquals('/', $request->uri);
    $this->assertEquals([], $request->headers);
    $this->assertEquals('', $request->body);
  }

  /**
   * Test that a request records the values it was built with.
   *
   * @param string $method
   *   HTTP method.
   * @param string $uri
   *   Request URI.
   * @param array<string, string> $headers
   *   Request headers.
   * @param string $body
   *   Request body.
   */
  #[DataProvider('dataProviderRequest')]
  public function testRequest(string $method, string $uri, array $headers, string $body): void {
    $request = new Request($method, $uri, $headers, $body);

    $this->assertEquals($method, $request->method);
    $this->assertEquals($uri, $request->uri);
    $this->assertEquals($headers, $request->headers);
    $this->assertEquals($body, $request->body);
  }

  /**
   * Data provider for request tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderRequest(): array {
    return [
      'queueing a response' => [
        'method' => 'PUT',
        'uri' => '/admin/responses',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => '{"key":"value"}',
      ],
      'clearing the queue' => [
        'method' => 'DELETE',
        'uri' => '/admin/responses',
        'headers' => [],
        'body' => '',
      ],
    ];
  }

  public function testSendResponsePrintsBody(): void {
    $output = $this->captureResponse(new Response(200, 'OK', ['X-Custom' => 'value'], 'hello'));

    $this->assertEquals('hello', $output);
  }

  /**
   * Test that a response without a body prints nothing.
   */
  public function testSendResponseWithEmptyBody(): void {
    $output = $this->captureResponse(new Response(204, 'No Content'));

    $this->assertEquals('', $output);
  }

  /**
   * Test that a response sends when the protocol is missing from $_SERVER.
   */
  public function testSendResponseWithoutServerProtocol(): void {
    $output = $this->captureResponse(new Response(200, 'OK', [], 'hello'), NULL);

    $this->assertEquals('hello', $output);
  }

  /**
   * Test that a failure is reported with a valid code and a single-line reason.
   *
   * @param \Throwable $throwable
   *   The failure to report.
   * @param int $expected_code
   *   Expected response code.
   * @param string $expected_reason
   *   Expected response reason.
   * @param string|null $expected_error
   *   Expected error in the response body, or NULL when it is the message.
   */
  #[DataProvider('dataProviderErrorResponse')]
  public function testErrorResponse(\Throwable $throwable, int $expected_code, string $expected_reason, ?string $expected_error = NULL): void {
    $response = static::callProtectedMethod(ApiServer::class, 'errorResponse', [$throwable]);

    $this->assertInstanceOf(Response::class, $response);
    $this->assertSame($expected_code, $response->code);
    $this->assertSame($expected_reason, $response->reason);
    $this->assertSame(['error' => $expected_error ?? $throwable->getMessage()], json_decode($response->body, TRUE));
  }

  /**
   * Data provider for error response tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderErrorResponse(): array {
    return [
      'code missing' => [
        'throwable' => new \RuntimeException('Failed to load data'),
        'expected_code' => 500,
        'expected_reason' => 'Failed to load data',
      ],
      'code within range' => [
        'throwable' => new \InvalidArgumentException('Invalid responses JSON payload provided', 400),
        'expected_code' => 400,
        'expected_reason' => 'Invalid responses JSON payload provided',
      ],
      'code at lower bound' => [
        'throwable' => new \Exception('Continue', 100),
        'expected_code' => 100,
        'expected_reason' => 'Continue',
      ],
      'code at upper bound' => [
        'throwable' => new \Exception('Unknown status', 599),
        'expected_code' => 599,
        'expected_reason' => 'Unknown status',
      ],
      'code below range' => [
        'throwable' => new \Exception('Too low', 99),
        'expected_code' => 500,
        'expected_reason' => 'Too low',
      ],
      'code above range' => [
        'throwable' => new \Exception('Too high', 600),
        'expected_code' => 500,
        'expected_reason' => 'Too high',
      ],
      'code negative' => [
        'throwable' => new \Exception('Negative', -1),
        'expected_code' => 500,
        'expected_reason' => 'Negative',
      ],
      'message spanning lines' => [
        'throwable' => new \Exception("Failed:\n  cause\r\nend"),
        'expected_code' => 500,
        'expected_reason' => 'Failed: cause end',
      ],
      'message empty' => [
        'throwable' => new \Exception(''),
        'expected_code' => 500,
        'expected_reason' => 'Unknown error',
      ],
      'message of control characters only' => [
        'throwable' => new \Exception("\n\t"),
        'expected_code' => 500,
        'expected_reason' => 'Unknown error',
      ],
      'message with invalid UTF-8' => [
        'throwable' => new \Exception("Broken \xB1 byte"),
        'expected_code' => 500,
        'expected_reason' => "Broken \xB1 byte",
        'expected_error' => "Broken \u{FFFD} byte",
      ],
    ];
  }

  /**
   * Test that a state file holding something other than state is reported.
   */
  public function testRunReportsInvalidStateFile(): void {
    file_put_contents($this->stateFile, serialize('not an array'));

    $output = $this->captureRun(static function (): void {
      ApiServer::run();
    });

    $this->assertSame(sprintf('Failed to load data from the server state file %s.', $this->stateFile), $this->errorFromResponse($output));
  }

  /**
   * Test that a state file holding data that is not serialised is reported.
   */
  public function testRunReportsMalformedStateFile(): void {
    file_put_contents($this->stateFile, 'not serialised at all');

    $output = $this->captureRun(static function (): void {
      ApiServer::run();
    });

    // The warning raised by deserialising is reported through the response
    // rather than printed into its body.
    $error = $this->errorFromResponse($output);

    $this->assertStringStartsWith(sprintf('Failed to load data from the server state file %s.', $this->stateFile), $error);
    $this->assertStringContainsString('unserialize()', $error);
  }

  /**
   * Test that a state file the server cannot read is reported.
   */
  public function testRunReportsUnreadableStateFile(): void {
    file_put_contents($this->stateFile, serialize(['requests' => [], 'responses' => []]));
    chmod($this->stateFile, 0000);
    clearstatcache(TRUE, $this->stateFile);

    if (is_readable($this->stateFile)) {
      $this->markTestSkipped('The current user can read a file that denies all permissions.');
    }

    $output = $this->captureRun(static function (): void {
      ApiServer::run();
    });

    $error = $this->errorFromResponse($output);

    $this->assertStringStartsWith(sprintf('Failed to read data from the server state file %s.', $this->stateFile), $error);
    $this->assertStringContainsString('file_get_contents(', $error);
  }

  /**
   * Test that a failure raised while serving the request is reported.
   */
  public function testRunReportsRequestFailure(): void {
    $server = new class() extends ApiServer {

      public function handleRequest(): void {
        throw new \RuntimeException('Handling failed', 503);
      }

    };

    $output = $this->captureRun(static function () use ($server): void {
      $server::run();
    });

    $this->assertSame('Handling failed', $this->errorFromResponse($output));
    $this->assertFileExists($this->stateFile);
  }

  /**
   * Test that an admin endpoint refuses a method it does not accept.
   *
   * @param string $method
   *   The refused HTTP method.
   * @param string $uri
   *   The admin endpoint.
   * @param string $expected_allow
   *   Expected value of the Allow header.
   */
  #[DataProvider('dataProviderHandleRequestRefusesMethod')]
  public function testHandleRequestRefusesMethod(string $method, string $uri, string $expected_allow): void {
    $server = $this->createServer(new Request($method, $uri));
    static::setProtectedValue($server, 'responses', [new Response(200, 'OK', [], 'queued')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame(sprintf('Method %s is not allowed on %s. Allowed methods: %s.', $method, $uri, $expected_allow), $this->errorFromResponse($output));

    // A refused request is neither recorded nor served from the queue.
    $this->assertCount(0, $this->serverState($server, 'requests'));
    $this->assertCount(1, $this->serverState($server, 'responses'));
  }

  /**
   * Data provider for refused method tests.
   *
   * @return array<string, array<string, string>>
   *   Test cases.
   */
  public static function dataProviderHandleRequestRefusesMethod(): array {
    return [
      'status with DELETE' => [
        'method' => 'DELETE',
        'uri' => '/admin/status',
        'expected_allow' => 'GET',
      ],
      'status with PUT' => [
        'method' => 'PUT',
        'uri' => '/admin/status',
        'expected_allow' => 'GET',
      ],
      'status with POST' => [
        'method' => 'POST',
        'uri' => '/admin/status',
        'expected_allow' => 'GET',
      ],
      'requests with PUT' => [
        'method' => 'PUT',
        'uri' => '/admin/requests',
        'expected_allow' => 'GET, DELETE',
      ],
      'responses with POST' => [
        'method' => 'POST',
        'uri' => '/admin/responses',
        'expected_allow' => 'GET, PUT, DELETE',
      ],
    ];
  }

  /**
   * Test that the refusal reports the methods the endpoint accepts.
   *
   * @param string $method
   *   The refused HTTP method.
   * @param string $uri
   *   The admin endpoint.
   * @param string $expected_allow
   *   Expected value of the Allow header.
   */
  #[DataProvider('dataProviderMethodNotAllowedResponse')]
  public function testMethodNotAllowedResponse(string $method, string $uri, string $expected_allow): void {
    $response = static::callProtectedMethod(ApiServer::class, 'methodNotAllowedResponse', [new Request($method, $uri), explode(', ', $expected_allow)]);

    $this->assertInstanceOf(Response::class, $response);
    $this->assertSame(405, $response->code);
    $this->assertSame('Method Not Allowed', $response->reason);
    $this->assertSame($expected_allow, $response->headers['Allow']);
    $this->assertSame('application/json', $response->headers['Content-Type']);
    $this->assertSame(['error' => sprintf('Method %s is not allowed on %s. Allowed methods: %s.', $method, $uri, $expected_allow)], json_decode($response->body, TRUE));
  }

  /**
   * Data provider for refusal response tests.
   *
   * One case per endpoint, because the Allow header varies by endpoint and
   * not by the method that was refused.
   *
   * @return array<string, array<string, string>>
   *   Test cases.
   */
  public static function dataProviderMethodNotAllowedResponse(): array {
    return [
      'status endpoint' => [
        'method' => 'DELETE',
        'uri' => '/admin/status',
        'expected_allow' => 'GET',
      ],
      'requests endpoint' => [
        'method' => 'PUT',
        'uri' => '/admin/requests',
        'expected_allow' => 'GET, DELETE',
      ],
      'responses endpoint' => [
        'method' => 'POST',
        'uri' => '/admin/responses',
        'expected_allow' => 'GET, PUT, DELETE',
      ],
    ];
  }

  /**
   * Test that the status endpoint reports the counts and touches nothing.
   */
  public function testHandleRequestServesStatus(): void {
    $server = $this->createServer(new Request('GET', '/admin/status'));
    static::setProtectedValue($server, 'requests', [new Request('GET', '/some/url')]);
    static::setProtectedValue($server, 'responses', [new Response(200, 'OK', [], 'queued')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame('', $output);
    $this->assertCount(1, $this->serverState($server, 'requests'));
    $this->assertCount(1, $this->serverState($server, 'responses'));
  }

  /**
   * Test that the recorded requests are served as JSON.
   */
  public function testHandleRequestServesRecordedRequests(): void {
    $server = $this->createServer(new Request('GET', '/admin/requests'));
    static::setProtectedValue($server, 'requests', [new Request('POST', '/some/url', ['X-Custom' => 'value'], 'payload')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $expected = [
      [
        'method' => 'POST',
        'uri' => '/some/url',
        'headers' => ['X-Custom' => 'value'],
        'body' => 'payload',
      ],
    ];

    $this->assertEquals($expected, json_decode($output, TRUE));
  }

  /**
   * Test that deleting the recorded requests leaves the queue alone.
   */
  public function testHandleRequestDeletesRecordedRequests(): void {
    $server = $this->createServer(new Request('DELETE', '/admin/requests'));
    static::setProtectedValue($server, 'requests', [new Request('GET', '/some/url')]);
    static::setProtectedValue($server, 'responses', [new Response(200, 'OK', [], 'queued')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame('', $output);
    $this->assertCount(0, $this->serverState($server, 'requests'));
    $this->assertCount(1, $this->serverState($server, 'responses'));
  }

  /**
   * Test that the queued responses are served as JSON.
   */
  public function testHandleRequestServesQueuedResponses(): void {
    $server = $this->createServer(new Request('GET', '/admin/responses'));
    static::setProtectedValue($server, 'responses', [new Response(204, 'No Content')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $expected = [
      [
        'body' => '',
        'code' => 204,
        'reason' => 'No Content',
        'headers' => [],
      ],
    ];

    $this->assertEquals($expected, json_decode($output, TRUE));
  }

  /**
   * Test that deleting the queue leaves the recorded requests alone.
   */
  public function testHandleRequestDeletesQueuedResponses(): void {
    $server = $this->createServer(new Request('DELETE', '/admin/responses'));
    static::setProtectedValue($server, 'requests', [new Request('GET', '/some/url')]);
    static::setProtectedValue($server, 'responses', [new Response(200, 'OK', [], 'queued')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame('', $output);
    $this->assertCount(1, $this->serverState($server, 'requests'));
    $this->assertCount(0, $this->serverState($server, 'responses'));
  }

  /**
   * Test that posted responses are appended to the queue.
   */
  public function testHandleRequestQueuesResponses(): void {
    $body = json_encode([
      ['code' => 200, 'reason' => 'OK', 'headers' => ['X-Custom' => 'value'], 'body' => base64_encode('first')],
      ['code' => 404, 'reason' => 'Not found'],
    ]);

    $server = $this->createServer(new Request('PUT', '/admin/responses', [], (string) $body));
    static::setProtectedValue($server, 'responses', [new Response(500, 'Server error')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame('', $output);

    $responses = $this->serverState($server, 'responses');

    $this->assertCount(3, $responses);
    $this->assertInstanceOf(Response::class, $responses[1]);
    $this->assertSame(200, $responses[1]->code);
    $this->assertSame('value', $responses[1]->headers['X-Custom']);
    $this->assertSame('first', $responses[1]->body);
    $this->assertInstanceOf(Response::class, $responses[2]);
    $this->assertSame(404, $responses[2]->code);
    $this->assertSame('Not found', $responses[2]->reason);
  }

  /**
   * Test that a payload that is not a list of valid responses queues nothing.
   *
   * @param string $body
   *   The posted body.
   * @param string $expected_message
   *   Expected exception message.
   */
  #[DataProvider('dataProviderHandleRequestRejectsInvalidResponsesPayload')]
  public function testHandleRequestRejectsInvalidResponsesPayload(string $body, string $expected_message): void {
    $server = $this->createServer(new Request('PUT', '/admin/responses', [], $body));
    static::setProtectedValue($server, 'responses', [new Response(200, 'OK', [], 'queued')]);
    $exception = NULL;

    // The queue is asserted after the throw, so the exception is captured
    // instead of declared with expectException().
    try {
      $server->handleRequest();
    }
    catch (\InvalidArgumentException $invalid_argument_exception) {
      $exception = $invalid_argument_exception;
    }

    $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    $this->assertSame(400, $exception->getCode());
    $this->assertSame($expected_message, $exception->getMessage());
    $this->assertCount(1, $this->serverState($server, 'responses'));
  }

  /**
   * Data provider for invalid response payload tests.
   *
   * @return array<string, array<string, string>>
   *   Test cases.
   */
  public static function dataProviderHandleRequestRejectsInvalidResponsesPayload(): array {
    return [
      'body is not JSON' => [
        'body' => 'not json',
        'expected_message' => 'Invalid responses JSON payload provided: Expected an array of response objects.',
      ],
      'body is a JSON scalar' => [
        'body' => '"a string"',
        'expected_message' => 'Invalid responses JSON payload provided: Expected an array of response objects.',
      ],
      'body is a single response object' => [
        'body' => '{"code": 200}',
        'expected_message' => 'Invalid responses JSON payload provided: Expected an array of response objects.',
      ],
      'element is not an object' => [
        'body' => '["not an object"]',
        'expected_message' => 'Invalid response #1 payload: Response must be an object.',
      ],
      'element has an invalid code' => [
        'body' => '[{"code": 200}, {"code": 42}]',
        'expected_message' => 'Invalid response #2 payload: Response code must be a number between 100 and 599.',
      ],
    ];
  }

  /**
   * Test that any other request is recorded and served from the queue.
   */
  public function testHandleRequestServesNextQueuedResponse(): void {
    $server = $this->createServer(new Request('POST', '/some/url'));
    static::setProtectedValue($server, 'responses', [new Response(201, 'Created', [], 'first'), new Response(200, 'OK', [], 'second')]);

    $output = $this->captureRun(static function () use ($server): void {
      $server->handleRequest();
    });

    $this->assertSame('first', $output);
    $this->assertCount(1, $this->serverState($server, 'requests'));
    $this->assertCount(1, $this->serverState($server, 'responses'));
  }

  /**
   * Test that a request arriving with an empty queue is reported.
   */
  public function testHandleRequestWithoutQueuedResponses(): void {
    $server = $this->createServer(new Request('GET', '/some/url'));

    $this->expectException(\Exception::class);
    $this->expectExceptionCode(500);
    $this->expectExceptionMessage('No responses in queue');

    $server->handleRequest();
  }

  /**
   * Create a server that serves the given request.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\Request $request
   *   The request to serve.
   *
   * @return \DrevOps\BehatPhpServer\ApiServer\ApiServer
   *   The server under test.
   */
  protected function createServer(Request $request): ApiServer {
    $server = new class() extends ApiServer {

      /**
       * The request to serve.
       */
      public ?Request $incomingRequest = NULL;

      /**
       * {@inheritdoc}
       */
      protected function createRequest(): Request {
        return $this->incomingRequest ?? new Request();
      }

    };

    $server->incomingRequest = $request;

    return $server;
  }

  /**
   * Read a state array from a server.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\ApiServer $server
   *   The server to read from.
   * @param string $property
   *   The property to read.
   *
   * @return array<mixed, mixed>
   *   The property value.
   */
  protected function serverState(ApiServer $server, string $property): array {
    $value = static::getProtectedValue($server, $property);

    $this->assertIsArray($value);

    return $value;
  }

  /**
   * Run a server entry point and capture what it printed.
   *
   * @param callable $runner
   *   The entry point to call.
   *
   * @return string
   *   The printed output.
   */
  protected function captureRun(callable $runner): string {
    ob_start();
    $runner();

    return (string) ob_get_clean();
  }

  /**
   * Read the message out of an error response body.
   *
   * @param string $output
   *   The response body.
   *
   * @return string
   *   The error message.
   */
  protected function errorFromResponse(string $output): string {
    $data = json_decode($output, TRUE);

    $this->assertIsArray($data);
    $this->assertArrayHasKey('error', $data);
    $this->assertIsString($data['error']);

    return $data['error'];
  }

  /**
   * Send a response and capture what it printed.
   *
   * Sending reads the protocol out of $_SERVER, so the original value is put
   * back afterwards to keep the tests independent of each other's order.
   *
   * @param \DrevOps\BehatPhpServer\ApiServer\Response $response
   *   The response to send.
   * @param string|null $protocol
   *   Protocol to expose in $_SERVER, or NULL to leave it unset.
   *
   * @return string
   *   The printed output.
   */
  protected function captureResponse(Response $response, ?string $protocol = 'HTTP/1.1'): string {
    $original_protocol = $_SERVER['SERVER_PROTOCOL'] ?? NULL;

    if ($protocol === NULL) {
      unset($_SERVER['SERVER_PROTOCOL']);
    }
    else {
      $_SERVER['SERVER_PROTOCOL'] = $protocol;
    }

    try {
      ob_start();
      ApiServer::sendResponse($response);

      return (string) ob_get_clean();
    }
    finally {
      if ($original_protocol === NULL) {
        unset($_SERVER['SERVER_PROTOCOL']);
      }
      else {
        $_SERVER['SERVER_PROTOCOL'] = $original_protocol;
      }
    }
  }

}
