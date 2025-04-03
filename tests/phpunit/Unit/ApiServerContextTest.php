<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use Behat\Gherkin\Node\PyStringNode;
use DrevOps\BehatPhpServer\ApiServerContext;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiServerContext::class)]
class ApiServerContextTest extends TestCase {

  /**
   * Test createHttpClient method.
   *
   * @param string $server_url
   *   Server URL to mock.
   * @param array<string, mixed> $additional_options
   *   Additional options for client.
   */
  #[DataProvider('dataProviderCreateHttpClient')]
  public function testCreateHttpClient(string $server_url, array $additional_options): void {
    // Create a mock for ApiServerContext.
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getServerUrl'])
      ->getMock();

    // Setup expected server URL.
    $context->expects($this->once())
      ->method('getServerUrl')
      ->willReturn($server_url);

    // Use reflection to call protected createHttpClient method.
    $reflectionClass = new \ReflectionClass(ApiServerContext::class);
    $createHttpClient = $reflectionClass->getMethod('createHttpClient');
    $createHttpClient->setAccessible(TRUE);

    // Call the method.
    $client = $createHttpClient->invoke($context, $additional_options);

    // Assert the result is a Client instance.
    $this->assertInstanceOf(Client::class, $client);
  }

  /**
   * Data provider for createHttpClient tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderCreateHttpClient(): array {
    return [
      'simple URL' => [
        'server_url' => 'http://test.example',
        'additional_options' => [],
      ],
      'with additional options' => [
        'server_url' => 'https://test.example',
        'additional_options' => ['verify' => FALSE],
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
    // Create a mock for ApiServerContext.
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['debug'])
      ->getMock();

    // Use reflection to call protected prepareResponse method.
    $reflectionClass = new \ReflectionClass(ApiServerContext::class);
    $prepareResponse = $reflectionClass->getMethod('prepareResponse');
    $prepareResponse->setAccessible(TRUE);

    // Call the method with the test input.
    $result = $prepareResponse->invoke($context, $json_input);

    // Basic assertions for all cases.
    $this->assertIsArray($result);
    $this->assertCount(1, $result);

    // Check each expected value.
    foreach ($expected_values as $key => $expected) {
      // Special case for base64 encoding assertion.
      if ($key === 'body_encoded' && isset($expected_values['body_raw'])) {
        $body_raw = $expected_values['body_raw'];
        $this->assertIsArray($result[0], 'Result should be an array');
        $this->assertArrayHasKey('body', $result[0], 'Result should have a body key');
        $this->assertIsString($body_raw, 'Body raw value should be a string');
        $this->assertEquals(
          base64_encode($body_raw),
          $result[0]['body'],
          "Body should be base64 encoded correctly"
        );
      }
      // Regular assertion.
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
    // Create a mock for ApiServerContext.
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['debug'])
      ->getMock();

    // Use reflection to call protected prepareResponse method.
    $reflectionClass = new \ReflectionClass(ApiServerContext::class);
    $prepareResponse = $reflectionClass->getMethod('prepareResponse');
    $prepareResponse->setAccessible(TRUE);

    // Test with the given invalid input.
    if (class_exists($exception_class)) {
      $this->expectException($exception_class);
      $this->expectExceptionMessage($exception_message);
      $prepareResponse->invoke($context, $json_input);
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
    // Create a mock for ApiServerContext.
    $context = $this->getMockBuilder(ApiServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['apiWillRespondWith'])
      ->getMock();

    // Create a PyStringNode.
    $pyStringNode = new PyStringNode([$json_content], 1);

    // Setup the mock expectation.
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

    // Call the method.
    $context->apiWillRespondWithJson($pyStringNode, $code);
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

}
