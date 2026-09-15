<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use DrevOps\BehatPhpServer\ApiServer\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
class ResponseTest extends TestCase {

  /**
   * Test Response::fromArray().
   *
   * @param array<string,mixed> $data
   *   The data to test.
   * @param \DrevOps\BehatPhpServer\ApiServer\Response|null $expected_response
   *   The expected response.
   * @param string|null $exception
   *   The expected exception message.
   */
  #[DataProvider('dataProviderFromArray')]
  public function testFromArray(array $data, ?Response $expected_response, ?string $exception = NULL): void {
    if ($exception) {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage($exception);
    }

    $result = Response::fromArray($data);

    if (!$exception) {
      $this->assertEquals($expected_response, $result);
    }
  }

  /**
   * Data provider for testFromArray().
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderFromArray(): array {
    return [
      'code only' => [
        'data' => ['code' => 200],
        'expected_response' => new Response(),
      ],
      'client error code' => [
        'data' => ['code' => 404],
        'expected_response' => new Response(404),
      ],
      'code and reason' => [
        'data' => ['code' => 200, 'reason' => 'OK'],
        'expected_response' => new Response(200, 'OK'),
      ],
      'encoded JSON body' => [
        'data' => ['code' => 200, 'reason' => 'OK', 'body' => base64_encode((string) json_encode(['key' => 'value']))],
        'expected_response' => new Response(200, 'OK', [], ['key' => 'value']),
      ],
      'server error code' => [
        'data' => ['code' => 500],
        'expected_response' => new Response(500),
      ],
      'custom reason' => [
        'data' => ['code' => 500, 'reason' => 'Custom error'],
        'expected_response' => new Response(500, 'Custom error'),
      ],
      'headers' => [
        'data' => ['code' => 200, 'reason' => 'OK', 'headers' => ['Content-Type' => 'application/json']],
        'expected_response' => new Response(200, 'OK', ['Content-Type' => 'application/json']),
      ],
      'headers with an empty body' => [
        'data' => ['code' => 200, 'reason' => 'OK', 'headers' => ['Content-Type' => 'application/json'], 'body' => ''],
        'expected_response' => new Response(200, 'OK', ['Content-Type' => 'application/json'], ''),
      ],
      'headers with an encoded text body' => [
        'data' => ['code' => 200, 'reason' => 'OK', 'headers' => ['customheader' => 'customheadervalue'], 'body' => base64_encode('Hello, World!')],
        'expected_response' => new Response(200, 'OK', ['customheader' => 'customheadervalue'], 'Hello, World!'),
      ],
      'reason that is falsy' => [
        'data' => ['code' => 200, 'reason' => '0'],
        'expected_response' => new Response(200, '0'),
      ],
      'unknown key' => [
        'data' => ['code' => 200, 'method' => 'PATCH'],
        'expected_response' => new Response(200),
      ],
      'empty reason' => [
        'data' => ['code' => 200, 'reason' => ''],
        'expected_response' => NULL,
        'exception' => 'Reason must be a non-empty string.',
      ],
      'non-string reason' => [
        'data' => ['code' => 200, 'reason' => []],
        'expected_response' => NULL,
        'exception' => 'Reason must be a non-empty string.',
      ],
      'empty code' => [
        'data' => ['code' => ''],
        'expected_response' => NULL,
        'exception' => 'Response code is required.',
      ],
      'non-numeric code' => [
        'data' => ['code' => 'status'],
        'expected_response' => NULL,
        'exception' => 'Response code must be a number between 100 and 599.',
      ],
      'code below range' => [
        'data' => ['code' => 2],
        'expected_response' => NULL,
        'exception' => 'Response code must be a number between 100 and 599.',
      ],
      'code above range' => [
        'data' => ['code' => 600],
        'expected_response' => NULL,
        'exception' => 'Response code must be a number between 100 and 599.',
      ],
      'empty headers' => [
        'data' => ['code' => 200, 'headers' => ''],
        'expected_response' => NULL,
        'exception' => 'Headers must be an array.',
      ],
      'string headers' => [
        'data' => ['code' => 200, 'headers' => 'invalid'],
        'expected_response' => NULL,
        'exception' => 'Headers must be an array.',
      ],
      'header without a name' => [
        'data' => ['code' => 200, 'headers' => [123]],
        'expected_response' => NULL,
        'exception' => 'Header "0" value must be a string.',
      ],
      'non-scalar header value' => [
        'data' => ['code' => 200, 'headers' => ['header' => [123]]],
        'expected_response' => NULL,
        'exception' => 'Header "header" value must be a string.',
      ],
      'non-string body' => [
        'data' => ['code' => 200, 'body' => []],
        'expected_response' => NULL,
        'exception' => 'Body must be a string.',
      ],
    ];
  }

  /**
   * Test that a JSON body sets the content type unless one is already set.
   *
   * @param array<string, string> $headers
   *   Headers passed to the response.
   * @param mixed $body
   *   Body passed to the response.
   * @param array<string, string> $expected_headers
   *   Expected response headers.
   */
  #[DataProvider('dataProviderContentType')]
  public function testContentType(array $headers, mixed $body, array $expected_headers): void {
    $response = new Response(200, 'OK', $headers, $body);

    $this->assertSame($expected_headers, $response->headers);
  }

  /**
   * Data provider for testContentType().
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderContentType(): array {
    return [
      'empty body' => [
        'headers' => [],
        'body' => '',
        'expected_headers' => [],
      ],
      'text body' => [
        'headers' => [],
        'body' => 'hello',
        'expected_headers' => ['Content-Length' => '5'],
      ],
      'JSON text body' => [
        'headers' => [],
        'body' => '{"key":"value"}',
        'expected_headers' => ['Content-Type' => 'application/json', 'Content-Length' => '15'],
      ],
      'JSON text body with a content type' => [
        'headers' => ['Content-Type' => 'text/plain'],
        'body' => '42',
        'expected_headers' => ['Content-Type' => 'text/plain', 'Content-Length' => '2'],
      ],
      'JSON text body with a lowercase content type' => [
        'headers' => ['content-type' => 'text/plain'],
        'body' => '42',
        'expected_headers' => ['content-type' => 'text/plain', 'Content-Length' => '2'],
      ],
      'array body' => [
        'headers' => [],
        'body' => ['key' => 'value'],
        'expected_headers' => ['Content-Type' => 'application/json', 'Content-Length' => '15'],
      ],
      'array body with a content type' => [
        'headers' => ['Content-Type' => 'application/problem+json'],
        'body' => ['key' => 'value'],
        'expected_headers' => ['Content-Type' => 'application/problem+json', 'Content-Length' => '15'],
      ],
    ];
  }

}
