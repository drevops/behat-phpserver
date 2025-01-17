<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer;

use Behat\Gherkin\Node\PyStringNode;
use GuzzleHttp\Client;

/**
 * Class ApiServerContext.
 *
 * Behat context to enable ApiServer support in tests.
 *
 * @see \DrevOps\BehatPhpServer\ApiServer
 *
 * @package DrevOps\BehatPhpServer
 */
class ApiServerContext extends PhpServerContext {

  /**
   * {@inheritdoc}
   */
  const TAG = 'apiserver';

  /**
   * {@inheritdoc}
   */
  const DEFAULT_WEBROOT = __DIR__ . '/../../../apiserver';

  /**
   * Put expected response data to the API server.
   *
   * @Given (the )API will respond with:
   *
   * @code
   * Given API will respond with:
   * """
   * {
   *   "code": 200,
   *   "reason": "OK",
   *   "headers": {
   *     "Content-Type": "application/json"
   *   },
   *   "body": {
   *     "Id": "test-id-1",
   *     "Slug": "test-slug-1"
   *   }
   * }
   * """
   * @endcode
   */
  public function apiWillRespondWith(PyStringNode $data): void {
    $data = $this->prepareData($data->getRaw());

    $client = new Client([
      'base_uri' => 'http://' . $this->host . ':' . $this->port,
      'http_errors' => FALSE,
    ]);

    $response = $client->request('PUT', '/admin/responses', [
      'json' => $data,
    ]);

    if ($response->getStatusCode() !== 201) {
      throw new \RuntimeException('Failed to set the API response.');
    }
  }

  /**
   * Process the response data.
   *
   * @param string $data
   *   The response data.
   *
   * @return array<int, array<string, mixed>>
   *   The response data.
   */
  protected function prepareData(string $data): array {
    // Validate the JSON.
    $data = json_decode($data, TRUE);
    if ($data === NULL || !is_array($data)) {
      throw new \InvalidArgumentException('Request data is not a valid JSON.');
    }

    $data += [
      'code' => 200,
      // @todo Validate reason.
      'reason' => 'OK',
      'headers' => [],
      'body' => '',
    ];

    if (!is_numeric($data['code'])) {
      throw new \InvalidArgumentException('Status code must be a number.');
    }
    $data['code'] = intval($data['code']);

    if (!is_array($data['headers'])) {
      throw new \InvalidArgumentException('Headers must be an array.');
    }

    // Check that the headers are valid.
    foreach ($data['headers'] as $header_name => $header_value) {
      if (!is_string($header_name) || !is_string($header_value)) {
        throw new \InvalidArgumentException(sprintf('Header %s value must be a string.', $header_name));
      }
    }

    // Convert the body to a JSON string as it would be in a real response.
    if (isset($data['body']) || $data['body'] !== NULL) {
      if (is_array($data['body'])) {
        $data['body'] = json_encode($data['body']);
      }

      $data['body'] = \base64_encode($data['body']);
    }

    return [$data];
  }

}
