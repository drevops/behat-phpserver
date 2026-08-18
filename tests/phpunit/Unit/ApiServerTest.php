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
   */
  #[DataProvider('dataProviderErrorResponse')]
  public function testErrorResponse(\Throwable $throwable, int $expected_code, string $expected_reason): void {
    $response = static::callProtectedMethod(ApiServer::class, 'errorResponse', [$throwable]);

    $this->assertInstanceOf(Response::class, $response);
    $this->assertSame($expected_code, $response->code);
    $this->assertSame($expected_reason, $response->reason);
    $this->assertSame(['error' => $throwable->getMessage()], json_decode($response->body, TRUE));
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

    $this->assertSame(sprintf('Failed to load data from the server state file %s', $this->stateFile), $this->errorFromResponse($output));
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
    }, TRUE);

    $this->assertSame(sprintf('Failed to read data from the server state file %s', $this->stateFile), $this->errorFromResponse($output));
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
   * Run a server entry point and capture what it printed.
   *
   * @param callable $runner
   *   The entry point to call.
   * @param bool $suppress_warnings
   *   Whether to swallow the PHP warnings raised while running.
   *
   * @return string
   *   The printed output.
   */
  protected function captureRun(callable $runner, bool $suppress_warnings = FALSE): string {
    if ($suppress_warnings) {
      set_error_handler(static fn(): bool => TRUE);
    }

    try {
      ob_start();
      $runner();

      return (string) ob_get_clean();
    }
    finally {
      if ($suppress_warnings) {
        restore_error_handler();
      }
    }
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
