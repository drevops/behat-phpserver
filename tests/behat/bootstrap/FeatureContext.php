<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends MinkContext implements Context {

  /**
   * The PHP server URL.
   */
  protected string $phpServerUrl;

  /**
   * The API server URL.
   */
  protected string $apiServerUrl;

  /**
   * Read the server URLs from the registered server contexts.
   */
  #[BeforeScenario]
  public function beforeScenarioInit(BeforeScenarioScope $scope): void {
    $environment = $scope->getEnvironment();

    if (!$environment instanceof InitializedContextEnvironment) {
      throw new \Exception('Environment is not initialized.');
    }

    $context = $environment->getContext(PhpServerContext::class);
    $this->phpServerUrl = $context->getServerUrl();

    $context = $environment->getContext(ApiServerContext::class);
    $this->apiServerUrl = $context->getServerUrl();
  }

  /**
   * Visit the test page served by the PHP server.
   */
  #[Given('(I )am on (the )phpserver test page')]
  #[When('(I )go to (the )phpserver test page')]
  public function goToPhpServerTestPage(): void {
    $this->getSession()->visit($this->phpServerUrl . '/test_page.html');
  }

  /**
   * Send a request with the given method to the API server.
   */
  #[When('I send a :method request to :path in the API server')]
  public function sendRequestToApiServer(string $method, string $path): void {
    $driver = $this->getSession()->getDriver();

    if (!$driver instanceof BrowserKitDriver) {
      throw new \Exception('This step requires BrowserKitDriver.');
    }

    $driver->getClient()->request($method, $this->apiServerUrl . $path);
  }

  /**
   * Assert that a response header contains a value, ignoring case.
   */
  #[Then('the response header should contain :name with value :value')]
  public function theResponseHeaderShouldContain(string $name, string $value): void {
    $actual = (string) $this->getSession()->getResponseHeader($name);
    $message = sprintf('The header "%s" does not contain the value "%s", but has a value of "%s".', $name, $value, $actual);

    if (!str_contains(strtolower($actual), strtolower($value))) {
      throw new \Exception($message);
    }
  }

  /**
   * Assert that the response does not carry a header.
   */
  #[Then('the response should not contain header :name')]
  public function theResponseShouldNotContainHeader(string $name): void {
    $actual = (string) $this->getSession()->getResponseHeader($name);
    $message = sprintf('The header "%s" is present in the response with a value of "%s", but it should not be.', $name, $actual);

    if ($actual !== '') {
      throw new \Exception($message);
    }
  }

  /**
   * Send a GET request to the API server.
   */
  #[When('I send a GET request to :path')]
  public function sendGetRequestToApiServer(string $path): void {
    $this->sendRequestToApiServer('GET', $path);
  }

  /**
   * Assert that a response header equals a value.
   */
  #[Then('the response header :name should be :value')]
  public function theResponseHeaderShouldBe(string $name, string $value): void {
    $actual = (string) $this->getSession()->getResponseHeader($name);
    $message = sprintf('The header "%s" does not have the value "%s", but has a value of "%s".', $name, $value, $actual);

    if ($actual !== $value) {
      throw new \Exception($message);
    }
  }

  /**
   * Assert that the response is an HTML document.
   */
  #[Then('the response should be HTML')]
  public function theResponseShouldBeHtml(): void {
    $content_type = (string) $this->getSession()->getResponseHeader('Content-Type');
    $message = sprintf('The response is not HTML, but has Content-Type "%s".', $content_type);

    if (!str_contains(strtolower($content_type), 'text/html')) {
      throw new \Exception($message);
    }

    $content = $this->getSession()->getPage()->getContent();

    if (!str_contains($content, '<html') && !str_contains($content, '<body')) {
      throw new \Exception('The response content does not appear to be HTML.');
    }
  }

}
