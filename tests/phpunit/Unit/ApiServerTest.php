<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use DrevOps\BehatPhpServer\ApiServer\ApiServer;
use DrevOps\BehatPhpServer\ApiServer\Request;
use DrevOps\BehatPhpServer\ApiServer\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiServer::class)]
#[CoversClass(Request::class)]
class ApiServerTest extends TestCase {

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
