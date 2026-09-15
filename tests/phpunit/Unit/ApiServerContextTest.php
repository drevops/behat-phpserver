<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\Tests\Traits\BehatDefinitionTrait;
use DrevOps\BehatPhpServer\Tests\Traits\ReflectionTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the API server context.
 *
 * @phpstan-type HistoryTransaction array{request: \Psr\Http\Message\RequestInterface, response: \Psr\Http\Message\ResponseInterface|null, error: mixed, options: array<array-key, mixed>}
 */
#[CoversClass(ApiServerContext::class)]
class ApiServerContextTest extends TestCase {

  use BehatDefinitionTrait;
  use ReflectionTrait;

  /**
   * Test that the created client applies its options to a request.
   *
   * @param string $server_url
   *   Server URL to mock.
   * @param array<string, mixed> $additional_options
   *   Additional options for client.
   * @param string $expected_uri
   *   The URI the request is expected to be sent to.
   * @param array<string, mixed> $expected_options
   *   Request options expected to reach the handler.
   */
  #[DataProvider('dataProviderCreateHttpClient')]
  public function testCreateHttpClient(string $server_url, array $additional_options, string $expected_uri, array $expected_options): void {
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getServerUrl'])
      ->getMock();

    $context->expects($this->once())
      ->method('getServerUrl')
      ->willReturn($server_url);

    $history = new \ArrayObject();
    $options = ['handler' => $this->createHandlerStack([new Response(500)], $history)] + $additional_options;

    $client = static::callProtectedMethod($context, 'createHttpClient', [$options]);

    $this->assertInstanceOf(Client::class, $client);

    $response = $client->request('GET', '/admin/status');

    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame($expected_uri, (string) $this->getHistoryRequest($history, 0)->getUri());

    $sent_options = $this->getHistoryTransaction($history, 0)['options'];

    foreach ($expected_options as $name => $expected) {
      $this->assertArrayHasKey($name, $sent_options);
      $this->assertSame($expected, $sent_options[$name]);
    }
  }

  /**
   * Data provider for createHttpClient tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderCreateHttpClient(): array {
    return [
      'defaults' => [
        'server_url' => 'http://test.example',
        'additional_options' => [],
        'expected_uri' => 'http://test.example/admin/status',
        'expected_options' => [
          RequestOptions::HTTP_ERRORS => FALSE,
          RequestOptions::CONNECT_TIMEOUT => ApiServerContext::DEFAULT_CONNECT_TIMEOUT,
          RequestOptions::TIMEOUT => ApiServerContext::DEFAULT_REQUEST_TIMEOUT,
          RequestOptions::READ_TIMEOUT => ApiServerContext::DEFAULT_READ_TIMEOUT,
        ],
      ],
      'additional option' => [
        'server_url' => 'https://test.example',
        'additional_options' => [RequestOptions::VERIFY => FALSE],
        'expected_uri' => 'https://test.example/admin/status',
        'expected_options' => [
          RequestOptions::HTTP_ERRORS => FALSE,
          RequestOptions::CONNECT_TIMEOUT => ApiServerContext::DEFAULT_CONNECT_TIMEOUT,
          RequestOptions::TIMEOUT => ApiServerContext::DEFAULT_REQUEST_TIMEOUT,
          RequestOptions::READ_TIMEOUT => ApiServerContext::DEFAULT_READ_TIMEOUT,
          RequestOptions::VERIFY => FALSE,
        ],
      ],
      'additional option replaces a default' => [
        'server_url' => 'http://test.example',
        'additional_options' => [RequestOptions::TIMEOUT => 30],
        'expected_uri' => 'http://test.example/admin/status',
        'expected_options' => [
          RequestOptions::HTTP_ERRORS => FALSE,
          RequestOptions::CONNECT_TIMEOUT => ApiServerContext::DEFAULT_CONNECT_TIMEOUT,
          RequestOptions::TIMEOUT => 30,
          RequestOptions::READ_TIMEOUT => ApiServerContext::DEFAULT_READ_TIMEOUT,
        ],
      ],
    ];
  }

  /**
   * Test prepareResponse method with various inputs.
   *
   * @param string $json_input
   *   JSON input to test.
   * @param array<string, mixed> $expected_values
   *   Expected values to check in the result.
   */
  #[DataProvider('dataProviderPrepareResponse')]
  public function testPrepareResponse(string $json_input, array $expected_values): void {
    $context = $this->getStubBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['printDebug'])
      ->getStub();

    $result = static::callProtectedMethod($context, 'prepareResponse', [$json_input]);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);

    foreach ($expected_values as $key => $expected) {
      if ($key === 'body_encoded' && isset($expected_values['body_raw'])) {
        $body_raw = $expected_values['body_raw'];
        $this->assertIsArray($result[0], 'Result should be an array');
        $this->assertArrayHasKey('body', $result[0], 'Result should have a body key');
        $this->assertIsString($body_raw, 'Body raw value should be a string');
        $this->assertEquals(base64_encode($body_raw), $result[0]['body'], 'Body should be base64 encoded correctly');
      }
      elseif ($key !== 'body_raw') {
        $path = explode('.', $key);
        $value = $result[0];
        $this->assertIsArray($value, 'Result should be an array');

        foreach ($path as $segment) {
          $this->assertIsArray($value, 'Value should be an array before accessing key');
          $this->assertArrayHasKey($segment, $value, sprintf('Array should have key "%s"', $segment));
          $value = $value[$segment];
        }

        $this->assertEquals($expected, $value);
      }
    }
  }

  /**
   * Data provider for prepareResponse tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderPrepareResponse(): array {
    return [
      'code only' => [
        'json_input' => '{"code": 200}',
        'expected_values' => [
          'code' => 200,
          'reason' => 'OK',
        ],
      ],
      'full response' => [
        'json_input' => '{"code": 404, "reason": "Not Found", "headers": {"Content-Type": "application/json"}, "body": "test"}',
        'expected_values' => [
          'code' => 404,
          'reason' => 'Not Found',
          'headers.Content-Type' => 'application/json',
          'body_raw' => 'test',
        ],
      ],
      'array body' => [
        'json_input' => '{"code": 200, "body": {"key": "value"}}',
        'expected_values' => [
          'code' => 200,
          'body_raw' => json_encode(['key' => 'value']),
        ],
      ],
    ];
  }

  /**
   * Test prepareResponse with invalid inputs.
   *
   * @param string $json_input
   *   JSON input to test.
   * @param class-string<\Throwable> $exception_class
   *   Expected exception class.
   * @param string $exception_message
   *   Expected exception message.
   */
  #[DataProvider('dataProviderPrepareResponseInvalid')]
  public function testPrepareResponseInvalid(string $json_input, string $exception_class, string $exception_message): void {
    $context = $this->getStubBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['printDebug'])
      ->getStub();

    if (class_exists($exception_class)) {
      $this->expectException($exception_class);
      $this->expectExceptionMessage($exception_message);
      static::callProtectedMethod($context, 'prepareResponse', [$json_input]);
    }
    else {
      $this->fail(sprintf('Exception class %s does not exist', $exception_class));
    }
  }

  /**
   * Data provider for prepareResponse with invalid inputs.
   *
   * @return array<string, array{json_input: string, exception_class: class-string<\Throwable>, exception_message: string}>
   *   Test cases.
   */
  public static function dataProviderPrepareResponseInvalid(): array {
    return [
      'invalid JSON' => [
        'json_input' => 'invalid json',
        'exception_class' => \InvalidArgumentException::class,
        'exception_message' => 'Request data is not a valid JSON.',
      ],
      'non-numeric code' => [
        'json_input' => '{"code": "not-a-number"}',
        'exception_class' => \InvalidArgumentException::class,
        'exception_message' => 'Status code must be a number.',
      ],
      'non-array headers' => [
        'json_input' => '{"code": 200, "headers": "not-an-array"}',
        'exception_class' => \InvalidArgumentException::class,
        'exception_message' => 'Headers must be an array.',
      ],
    ];
  }

  /**
   * Test apiWillRespondWithJson method with different scenarios.
   *
   * @param string $json_content
   *   JSON content for PyStringNode.
   * @param string|null $code
   *   Status code parameter or null.
   * @param int $expected_code
   *   Expected code in the result.
   */
  #[DataProvider('dataProviderApiWillRespondWithJson')]
  public function testApiWillRespondWithJson(string $json_content, ?string $code, int $expected_code): void {
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['apiWillRespondWith'])
      ->getMock();

    $py_string_node = new PyStringNode([$json_content], 1);

    $context->expects($this->once())
      ->method('apiWillRespondWith')
      ->willReturnCallback(function (PyStringNode $node) use ($expected_code): null {
        $raw_data = $node->getRaw();
        $data = json_decode($raw_data, TRUE);
        $this->assertIsArray($data, 'Decoded data should be an array');
        $this->assertArrayHasKey('code', $data, 'Data should have a code key');
        $this->assertEquals($expected_code, $data['code']);
        $this->assertArrayHasKey('body', $data, 'Data should have a body key');
        $this->assertIsArray($data['body'], 'Body should be an array');

        return NULL;
      });

    $context->apiWillRespondWithJson($py_string_node, $code);
  }

  /**
   * Data provider for apiWillRespondWithJson tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderApiWillRespondWithJson(): array {
    return [
      'default code' => [
        'json_content' => '{"key": "value"}',
        'code' => NULL,
        'expected_code' => 200,
      ],
      'custom code' => [
        'json_content' => '{"key": "value"}',
        'code' => '201',
        'expected_code' => 201,
      ],
      'custom code 404' => [
        'json_content' => '{"error": "not found"}',
        'code' => '404',
        'expected_code' => 404,
      ],
    ];
  }

  /**
   * Test fixture paths in constructor.
   *
   * @param array<string>|string|null $paths
   *   Fixture paths to test.
   * @param array<string> $expected_paths
   *   Expected fixture paths.
   */
  #[DataProvider('dataProviderConstructorFixturesPaths')]
  public function testConstructorFixturesPaths(array|string|null $paths, array $expected_paths): void {
    $context = new ApiServerContext(paths: $paths);

    $this->assertSame($expected_paths, self::getProtectedValue($context, 'fixturesPaths'));
  }

  /**
   * Data provider for testConstructorFixturesPaths.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderConstructorFixturesPaths(): array {
    return [
      'no paths' => [
        'paths' => NULL,
        'expected_paths' => [],
      ],
      'string path' => [
        'paths' => '/path/to/fixtures',
        'expected_paths' => ['/path/to/fixtures'],
      ],
      'array of paths' => [
        'paths' => ['/path/to/fixtures1', '/path/to/fixtures2'],
        'expected_paths' => ['/path/to/fixtures1', '/path/to/fixtures2'],
      ],
      'empty array' => [
        'paths' => [],
        'expected_paths' => [],
      ],
      'empty string' => [
        'paths' => '',
        'expected_paths' => [],
      ],
      'numeric string path' => [
        'paths' => '123',
        'expected_paths' => ['123'],
      ],
    ];
  }

  /**
   * Test that the webroot falls back to the bundled API server directory.
   *
   * @param string|null $webroot
   *   Webroot passed to the constructor.
   * @param string $expected_webroot
   *   Expected webroot.
   */
  #[DataProvider('dataProviderConstructorWebroot')]
  public function testConstructorWebroot(?string $webroot, string $expected_webroot): void {
    $context = new ApiServerContext($webroot);

    $this->assertSame($expected_webroot, self::getProtectedValue($context, 'webroot'));
  }

  /**
   * Data provider for testConstructorWebroot.
   *
   * @return array<string, array<string, string|null>>
   *   Test cases.
   */
  public static function dataProviderConstructorWebroot(): array {
    return [
      'not set' => [
        'webroot' => NULL,
        'expected_webroot' => ApiServerContext::DEFAULT_WEBROOT,
      ],
      'empty' => [
        'webroot' => '',
        'expected_webroot' => ApiServerContext::DEFAULT_WEBROOT,
      ],
      'custom' => [
        'webroot' => __DIR__,
        'expected_webroot' => __DIR__,
      ],
    ];
  }

  /**
   * Create a context whose HTTP client returns a canned set of responses.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response> $queue
   *   Responses to return, in the order they are requested.
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Populated with the transactions the client performed.
   * @param string[] $fixtures_paths
   *   Fixture paths to configure on the context.
   *
   * @return \PHPUnit\Framework\MockObject\Stub&\DrevOps\BehatPhpServer\ApiServerContext
   *   Context with a canned client and stubbed server lifecycle methods.
   */
  protected function createContextWithClient(array $queue, \ArrayObject $history = new \ArrayObject(), array $fixtures_paths = []): ApiServerContext {
    $context = $this->getStubBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['isRunning', 'start'])
      ->getStub();

    $this->replaceClient($context, $queue, $history, $fixtures_paths);

    return $context;
  }

  /**
   * Create a context mock whose HTTP client returns a canned set of responses.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response> $queue
   *   Responses to return, in the order they are requested.
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Populated with the transactions the client performed.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject&\DrevOps\BehatPhpServer\ApiServerContext
   *   Context with a canned client and mocked server lifecycle methods.
   */
  protected function createMockContextWithClient(array $queue, \ArrayObject $history = new \ArrayObject()): ApiServerContext {
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['isRunning', 'start'])
      ->getMock();

    $this->replaceClient($context, $queue, $history);

    return $context;
  }

  /**
   * Replace the HTTP client of a context with one that returns canned responses.
   *
   * @param \DrevOps\BehatPhpServer\ApiServerContext $context
   *   Context to configure.
   * @param array<int, \GuzzleHttp\Psr7\Response> $queue
   *   Responses to return, in the order they are requested.
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Populated with the transactions the client performed.
   * @param string[] $fixtures_paths
   *   Fixture paths to configure on the context.
   */
  protected function replaceClient(ApiServerContext $context, array $queue, \ArrayObject $history, array $fixtures_paths = []): void {
    // Mirror the production client, which reports failures through the status
    // code rather than by throwing.
    $client = new Client(['handler' => $this->createHandlerStack($queue, $history), 'http_errors' => FALSE]);

    static::setProtectedValue($context, 'client', $client);
    static::setProtectedValue($context, 'debug', FALSE);
    static::setProtectedValue($context, 'fixturesPaths', $fixtures_paths);
  }

  /**
   * Create a handler stack that returns canned responses and records history.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response> $queue
   *   Responses to return, in the order they are requested.
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Populated with the transactions the client performed.
   *
   * @return \GuzzleHttp\HandlerStack<callable(\Psr\Http\Message\RequestInterface, array<array-key, mixed>): \GuzzleHttp\Promise\PromiseInterface<\Psr\Http\Message\ResponseInterface, mixed>>
   *   The handler stack.
   */
  protected function createHandlerStack(array $queue, \ArrayObject $history): HandlerStack {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    return $stack;
  }

  /**
   * Get the transaction recorded at the given position of the client history.
   *
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Transactions recorded by the client.
   * @param int $index
   *   Position to read.
   *
   * @return HistoryTransaction
   *   The recorded transaction.
   */
  protected function getHistoryTransaction(\ArrayObject $history, int $index): array {
    $transaction = $history[$index] ?? NULL;

    if ($transaction === NULL) {
      $this->fail(sprintf('No transaction was recorded at position %d.', $index));
    }

    return $transaction;
  }

  /**
   * Get the request recorded at the given position of the client history.
   *
   * @param \ArrayObject<int, HistoryTransaction> $history
   *   Transactions recorded by the client.
   * @param int $index
   *   Position to read.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The recorded request.
   */
  protected function getHistoryRequest(\ArrayObject $history, int $index): RequestInterface {
    return $this->getHistoryTransaction($history, $index)['request'];
  }

  /**
   * Decode the queued response carried by a recorded request.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The recorded request.
   *
   * @return array<mixed, mixed>
   *   The first queued response of the payload.
   */
  protected function decodeQueuedResponse(RequestInterface $request): array {
    $decoded = json_decode((string) $request->getBody(), TRUE);

    if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
      $this->fail('Request body does not carry a queued response payload.');
    }

    return $decoded[0];
  }

  /**
   * Read a string value out of a queued response payload.
   *
   * @param array<mixed, mixed> $payload
   *   The queued response payload.
   * @param string $key
   *   Key to read.
   *
   * @return string
   *   The value.
   */
  protected function getQueuedResponseString(array $payload, string $key): string {
    $value = $payload[$key] ?? NULL;

    if (!is_string($value)) {
      $this->fail(sprintf('Queued response key "%s" is not a string.', $key));
    }

    return $value;
  }

  /**
   * Read the headers out of a queued response payload.
   *
   * @param array<mixed, mixed> $payload
   *   The queued response payload.
   *
   * @return array<mixed, mixed>
   *   The headers.
   */
  protected function getQueuedResponseHeaders(array $payload): array {
    $headers = $payload['headers'] ?? NULL;

    if (!is_array($headers)) {
      $this->fail('Queued response does not carry headers.');
    }

    return $headers;
  }

  /**
   * Test that an already running server only gets a status check.
   */
  public function testApiIsRunningWhenServerResponds(): void {
    $history = new \ArrayObject();
    $context = $this->createMockContextWithClient([new Response(200)], $history);
    $context->expects($this->once())->method('isRunning')->willReturn(TRUE);
    $context->expects($this->never())->method('start');

    $context->apiIsRunning();

    $this->assertCount(1, $history);
    $this->assertEquals('/admin/status', (string) $this->getHistoryRequest($history, 0)->getUri());
  }

  /**
   * Test that a stopped server is started before the status check.
   */
  public function testApiIsRunningStartsStoppedServer(): void {
    $history = new \ArrayObject();
    $context = $this->createMockContextWithClient([new Response(200)], $history);
    $context->expects($this->once())->method('isRunning')->willReturn(FALSE);
    $context->expects($this->once())->method('start');

    $context->apiIsRunning();

    $this->assertCount(1, $history);
  }

  /**
   * Test that a non-200 status response is reported as a failure.
   */
  public function testApiIsRunningThrowsOnUnexpectedStatus(): void {
    $context = $this->createMockContextWithClient([new Response(503)]);
    $context->expects($this->once())->method('isRunning')->willReturn(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('API server is not up');

    $context->apiIsRunning();
  }

  /**
   * Test that resetting clears both the responses and the requests.
   */
  public function testApiIsReset(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([new Response(200), new Response(200)], $history);

    $context->apiIsReset();

    $this->assertCount(2, $history);

    $responses_request = $this->getHistoryRequest($history, 0);
    $this->assertEquals('DELETE', $responses_request->getMethod());
    $this->assertEquals('/admin/responses', (string) $responses_request->getUri());

    $requests_request = $this->getHistoryRequest($history, 1);
    $this->assertEquals('DELETE', $requests_request->getMethod());
    $this->assertEquals('/admin/requests', (string) $requests_request->getUri());
  }

  /**
   * Test that a failure of either delete is reported.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response> $queue
   *   Responses the client returns, in the order they are requested.
   * @param string $expected_message
   *   The expected exception message.
   * @param int $expected_requests
   *   The number of requests expected to reach the server.
   */
  #[DataProvider('dataProviderApiIsResetThrowsOnFailure')]
  public function testApiIsResetThrowsOnFailure(array $queue, string $expected_message, int $expected_requests): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient($queue, $history);
    $exception = NULL;

    // The transaction count is asserted after the throw, so the exception is
    // captured instead of declared with expectException(). Nothing inside the
    // try block may call fail(): AssertionFailedError descends from
    // RuntimeException, so the catch below would absorb it.
    try {
      $context->apiIsReset();
    }
    catch (\RuntimeException $runtime_exception) {
      $exception = $runtime_exception;
    }

    $this->assertInstanceOf(\RuntimeException::class, $exception);
    $this->assertEquals($expected_message, $exception->getMessage());
    $this->assertCount($expected_requests, $history);
  }

  /**
   * Data provider for reset failure tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderApiIsResetThrowsOnFailure(): array {
    return [
      'responses delete fails' => [
        'queue' => [new Response(500)],
        'expected_message' => 'Failed to delete the API responses.',
        'expected_requests' => 1,
      ],
      'requests delete fails' => [
        'queue' => [new Response(200), new Response(500)],
        'expected_message' => 'Failed to delete the API requests.',
        'expected_requests' => 2,
      ],
    ];
  }

  /**
   * Test clearing the queued responses.
   */
  public function testApiHasNoResponses(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([new Response(200)], $history);

    $context->apiHasNoResponses();

    $this->assertCount(1, $history);

    $request = $this->getHistoryRequest($history, 0);
    $this->assertEquals('DELETE', $request->getMethod());
    $this->assertEquals('/admin/responses', (string) $request->getUri());
  }

  /**
   * Test that a failure to clear the queued responses is reported.
   */
  public function testApiHasNoResponsesThrowsOnFailure(): void {
    $context = $this->createContextWithClient([new Response(500)]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to delete the API responses.');

    $context->apiHasNoResponses();
  }

  /**
   * Test that a failure to fetch the received requests is reported.
   */
  public function testDebugApiRequestsThrowsOnFailure(): void {
    $context = $this->createContextWithClient([new Response(500)]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to fetch the API requests.');

    $context->apiDebugRequests();
  }

  /**
   * Test queueing a response.
   */
  public function testApiWillRespondWith(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([new Response(201)], $history);

    $context->apiWillRespondWith(new PyStringNode(['{"code": 201, "body": "hello"}'], 1));

    $this->assertCount(1, $history);

    $request = $this->getHistoryRequest($history, 0);
    $this->assertEquals('PUT', $request->getMethod());
    $this->assertEquals('/admin/responses', (string) $request->getUri());

    $queued = $this->decodeQueuedResponse($request);
    $this->assertEquals(201, $queued['code']);
    $this->assertEquals('hello', base64_decode($this->getQueuedResponseString($queued, 'body')));
  }

  /**
   * Test that a failure to queue a response is reported.
   */
  public function testApiWillRespondWithThrowsOnFailure(): void {
    $context = $this->createContextWithClient([new Response(200)]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to set the API response.');

    $context->apiWillRespondWith(new PyStringNode(['{"code": 200}'], 1));
  }

  /**
   * Test that a file response carries the content type for its extension.
   *
   * @param string $file_path
   *   Fixture file to queue.
   * @param string $expected_type
   *   Expected Content-Type header.
   */
  #[DataProvider('dataProviderApiWillRespondWithFile')]
  public function testApiWillRespondWithFile(string $file_path, string $expected_type): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([new Response(201)], $history, [
      __DIR__ . '/../../behat/fixtures',
      __DIR__ . '/../../behat/fixtures2',
      __DIR__ . '/../../..',
    ]);

    $context->apiWillRespondWithFile($file_path);

    $queued = $this->decodeQueuedResponse($this->getHistoryRequest($history, 0));

    $this->assertEquals(200, $queued['code']);
    $this->assertEquals($expected_type, $this->getQueuedResponseHeaders($queued)['Content-Type'] ?? NULL);
    $this->assertNotEmpty($queued['body']);
  }

  /**
   * Data provider for file response tests.
   *
   * @return array<string, array<string, string>>
   *   Test cases.
   */
  public static function dataProviderApiWillRespondWithFile(): array {
    return [
      'json' => [
        'file_path' => 'test_data.json',
        'expected_type' => 'application/json',
      ],
      'xml' => [
        'file_path' => 'test_content.xml',
        'expected_type' => 'application/xml',
      ],
      'html' => [
        'file_path' => 'test_page.html',
        'expected_type' => 'text/html',
      ],
      'txt found in the second path' => [
        'file_path' => 'secondary_data.txt',
        'expected_type' => 'text/plain',
      ],
      'unknown extension falls back to binary' => [
        'file_path' => 'behat.yml',
        'expected_type' => 'application/octet-stream',
      ],
    ];
  }

  /**
   * Test queueing a file response with a custom status code.
   */
  public function testApiWillRespondWithFileCustomCode(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([new Response(201)], $history, [__DIR__ . '/../../behat/fixtures']);

    $context->apiWillRespondWithFile('test_data.json', '404');

    $queued = $this->decodeQueuedResponse($this->getHistoryRequest($history, 0));

    $this->assertEquals(404, $queued['code']);
  }

  /**
   * Test that a file response without fixture paths is reported.
   */
  public function testApiWillRespondWithFileThrowsWithoutPaths(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([], $history);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('File "test_data.json" cannot be found because no fixture paths are configured. Set the "paths" option of the context.');

    $context->apiWillRespondWithFile('test_data.json');
  }

  /**
   * Test that a missing fixture file reports every path that was searched.
   */
  public function testApiWillRespondWithFileThrowsWhenMissing(): void {
    $history = new \ArrayObject();
    $context = $this->createContextWithClient([], $history, ['/nonexistent/one', '/nonexistent/two']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('File "missing.json" does not exist in any of the configured fixture paths: /nonexistent/one, /nonexistent/two');

    $context->apiWillRespondWithFile('missing.json');
  }

  /**
   * Test asserting the number of queued responses.
   *
   * @param string $header_value
   *   Value of the header returned by the server.
   * @param string $expected_count
   *   Count to assert against.
   * @param bool $expect_exception
   *   Whether the assertion is expected to fail.
   */
  #[DataProvider('dataProviderAssertQueuedResponsesCount')]
  public function testAssertQueuedResponsesCount(string $header_value, string $expected_count, bool $expect_exception): void {
    $context = $this->createContextWithClient([new Response(200, ['X-Queued-Responses' => $header_value])]);

    if ($expect_exception) {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage(sprintf('Expected %s queued responses, got %s', $expected_count, $header_value));
    }

    $context->apiShouldHaveQueuedResponses($expected_count);

    $this->addToAssertionCount(1);
  }

  /**
   * Data provider for queued response count tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderAssertQueuedResponsesCount(): array {
    return [
      'matching count' => [
        'header_value' => '3',
        'expected_count' => '3',
        'expect_exception' => FALSE,
      ],
      'empty queue' => [
        'header_value' => '0',
        'expected_count' => '0',
        'expect_exception' => FALSE,
      ],
      'mismatched count' => [
        'header_value' => '1',
        'expected_count' => '5',
        'expect_exception' => TRUE,
      ],
    ];
  }

  /**
   * Test asserting the number of received requests.
   *
   * @param string $header_value
   *   Value of the header returned by the server.
   * @param string $expected_count
   *   Count to assert against.
   * @param bool $expect_exception
   *   Whether the assertion is expected to fail.
   */
  #[DataProvider('dataProviderAssertReceivedRequestsCount')]
  public function testAssertReceivedRequestsCount(string $header_value, string $expected_count, bool $expect_exception): void {
    $context = $this->createContextWithClient([new Response(200, ['X-Received-Requests' => $header_value])]);

    if ($expect_exception) {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage(sprintf('Expected %s received requests, got %s', $expected_count, $header_value));
    }

    $context->apiShouldHaveReceivedRequests($expected_count);

    $this->addToAssertionCount(1);
  }

  /**
   * Data provider for received request count tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderAssertReceivedRequestsCount(): array {
    return [
      'matching count' => [
        'header_value' => '2',
        'expected_count' => '2',
        'expect_exception' => FALSE,
      ],
      'no requests yet' => [
        'header_value' => '0',
        'expected_count' => '0',
        'expect_exception' => FALSE,
      ],
      'mismatched count' => [
        'header_value' => '4',
        'expected_count' => '1',
        'expect_exception' => TRUE,
      ],
    ];
  }

  /**
   * Test that each step method is declared with its step attributes.
   *
   * @param string $method
   *   Step method name.
   * @param array<int, array{0: string, 1: array<int|string, mixed>}> $expected_attributes
   *   Expected attribute class names paired with their arguments, in
   *   declaration order.
   */
  #[DataProvider('dataProviderStepAttributes')]
  public function testStepAttributes(string $method, array $expected_attributes): void {
    $this->assertSame($expected_attributes, static::getMethodAttributes(ApiServerContext::class, $method));
  }

  /**
   * Data provider for step attribute tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderStepAttributes(): array {
    return [
      'server is running' => [
        'method' => 'apiIsRunning',
        'expected_attributes' => [[Given::class, ['(the )API server is running']]],
      ],
      'server is reset' => [
        'method' => 'apiIsReset',
        'expected_attributes' => [[Given::class, ['(the )API server is reset']]],
      ],
      'no responses' => [
        'method' => 'apiHasNoResponses',
        'expected_attributes' => [[Given::class, ['(the )API has no responses']]],
      ],
      'debug requests' => [
        'method' => 'apiDebugRequests',
        'expected_attributes' => [[When::class, ['I debug API requests']]],
      ],
      'respond with' => [
        'method' => 'apiWillRespondWith',
        'expected_attributes' => [[Given::class, ['(the )API will respond with:']]],
      ],
      'respond with JSON' => [
        'method' => 'apiWillRespondWithJson',
        'expected_attributes' => [
          [Given::class, ['(the )API will respond with JSON:']],
          [Given::class, ['(the )API will respond with JSON and :code code:']],
        ],
      ],
      'respond with file' => [
        'method' => 'apiWillRespondWithFile',
        'expected_attributes' => [
          [Given::class, ['(the )API will respond with file :file_path']],
          [Given::class, ['(the )API will respond with file :file_path and :code code']],
        ],
      ],
      'queued responses' => [
        'method' => 'apiShouldHaveQueuedResponses',
        'expected_attributes' => [
          [Then::class, ['(the )API server should have :count queued response(s)']],
          [Then::class, ['(the )API server should have :count response(s) queued']],
        ],
      ],
      'received requests' => [
        'method' => 'apiShouldHaveReceivedRequests',
        'expected_attributes' => [
          [Then::class, ['(the )API server should have received :count request(s)']],
          [Then::class, ['(the )API server should have :count received request(s)']],
        ],
      ],
    ];
  }

  /**
   * Test that the context declares no Behat annotations.
   */
  public function testDeclaresNoBehatAnnotations(): void {
    $this->assertSame([], static::getBehatAnnotations(ApiServerContext::class));
  }

}
