<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use DrevOps\BehatPhpServer\ApiServer\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
class ResponseApiServerUnitTest extends TestCase {

  /**
   * Test Response::fromArray().
   *
   * @param array<string,mixed> $data
   *   The data to test.
   * @param \DrevOps\BehatPhpServer\ApiServer\Response|null $expected
   *   The expected response.
   * @param string|null $exception
   *   The expected exception message.
   */
  #[DataProvider('dataProviderFromArray')]
  public function testFromArray(array $data, ?Response $expected, ?string $exception = NULL): void {
    if ($exception) {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage($exception);
    }

    $actual = Response::fromArray($data);

    if (!$exception) {
      $this->assertEquals($expected, $actual);
    }
  }

  /**
   * Data provider for testFromArray().
   *
   * @return array<array<mixed>>
   *   The test data.
   */
  public static function dataProviderFromArray(): array {
    return [
      // Valid data.
      [['code' => 200], new Response(), NULL],
      [['code' => 404], new Response(404), NULL],
      [['code' => 200, 'reason' => 'OK'], new Response(200, 'OK'), NULL],

      [['code' => 500], new Response(500), NULL],
      [['code' => 500, 'reason' => 'Custom error'], new Response(500, 'Custom error'), NULL],

      [
        ['code' => 200, 'reason' => 'OK', 'headers' => ['Content-Type' => 'application/json']],
        new Response(200, 'OK', ['Content-Type' => 'application/json']),
        NULL,
      ],

      [
        ['code' => 200, 'reason' => 'OK', 'headers' => ['Content-Type' => 'application/json'], 'body' => ''],
        new Response(200, 'OK', ['Content-Type' => 'application/json'], ''),
        NULL,
      ],

      [
        ['code' => 200, 'reason' => 'OK', 'headers' => ['customheader' => 'customheadervalue'], 'body' => base64_encode('Hello, World!')],
        new Response(200, 'OK', ['customheader' => 'customheadervalue', 'Content-Length' => '7'], 'Hello, World!'),
        NULL,
      ],

      // Invalid: method.
      [['method' => 123], new Response(), 'Method must be a string.'],
      [['method' => []], new Response(), 'Method must be a string.'],
      [['method' => 'OTHER'], new Response(), 'Unsupported HTTP method "OTHER". Supported methods are GET, POST, PUT, DELETE.'],

      // Invalid: reason.
      [['code' => 200, 'reason' => ''], new Response(200), 'Reason must be a string.'],
      [['code' => 200, 'reason' => []], new Response(200), 'Reason must be a string.'],

      // Invalid: code.
      [['code' => ''], new Response(), 'Response code is required.'],
      [['code' => 'status'], new Response(), 'Response code must be a number between 100 and 599.'],
      [['code' => 2], new Response(), 'Response code must be a number between 100 and 599.'],
      [['code' => 600], new Response(), 'Response code must be a number between 100 and 599.'],

      // Invalid: headers.
      [['code' => 200, 'headers' => ''], new Response(200), 'Headers must be an array.'],
      [['code' => 200, 'headers' => 'invalid'], new Response(200), 'Headers must be an array.'],
      [['code' => 200, 'headers' => [123]], new Response(200), 'Header "0" value must be a string.'],
      [
        ['code' => 200, 'headers' => ['header' => [123]]],
        new Response(200),
        'Header "header" value must be a string.',
      ],

      // Invalid: body.
      [['code' => 200, 'body' => []], new Response(200), 'Body must be a string.'],
    ];
  }

}
