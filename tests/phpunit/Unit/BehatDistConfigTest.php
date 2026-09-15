<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use Behat\Config\Config;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
class BehatDistConfigTest extends TestCase {

  /**
   * Test that behat.dist.yml and behat.dist.php hold the same configuration.
   */
  public function testFormatsMatch(): void {
    $this->assertSame(static::loadYamlConfig(), static::loadPhpConfig());
  }

  /**
   * Test that the dist configuration sets every constructor option of a context.
   *
   * @param class-string $class
   *   The context class.
   */
  #[DataProvider('dataProviderSetsEveryOption')]
  public function testSetsEveryOption(string $class): void {
    $parameters = (new \ReflectionMethod($class, '__construct'))->getParameters();
    $expected = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);

    $this->assertSame($expected, array_keys(static::getContextOptions($class)));
  }

  /**
   * Data provider for testSetsEveryOption().
   *
   * @return array<string, array{class-string}>
   *   Test cases.
   */
  public static function dataProviderSetsEveryOption(): array {
    return [
      'php server' => [PhpServerContext::class],
      'api server' => [ApiServerContext::class],
    ];
  }

  /**
   * Load the configuration that behat.dist.php returns.
   *
   * @return array<mixed>
   *   The configuration as an array.
   */
  protected static function loadPhpConfig(): array {
    $config = require dirname(__DIR__, 3) . '/behat.dist.php';

    if (!$config instanceof Config) {
      self::fail('behat.dist.php does not return a Behat configuration.');
    }

    return $config->toArray();
  }

  /**
   * Load the configuration in behat.dist.yml.
   *
   * @return array<mixed>
   *   The configuration as an array.
   */
  protected static function loadYamlConfig(): array {
    $config = Yaml::parseFile(dirname(__DIR__, 3) . '/behat.dist.yml');

    if (!is_array($config)) {
      self::fail('behat.dist.yml does not hold a Behat configuration.');
    }

    return $config;
  }

  /**
   * Get the options that behat.dist.yml sets for a context.
   *
   * @param class-string $class
   *   The context class.
   *
   * @return array<mixed>
   *   The options, keyed by name.
   */
  protected static function getContextOptions(string $class): array {
    $contexts = static::loadYamlConfig();

    foreach (['default', 'suites', 'default', 'contexts'] as $key) {
      if (!is_array($contexts) || !isset($contexts[$key])) {
        self::fail(sprintf('behat.dist.yml has no "%s" key on the path to the suite contexts.', $key));
      }

      $contexts = $contexts[$key];
    }

    if (!is_array($contexts)) {
      self::fail('behat.dist.yml does not list the suite contexts.');
    }

    foreach ($contexts as $context) {
      if (is_array($context) && isset($context[$class]) && is_array($context[$class])) {
        return $context[$class];
      }
    }

    self::fail(sprintf('behat.dist.yml does not configure %s.', $class));
  }

}
