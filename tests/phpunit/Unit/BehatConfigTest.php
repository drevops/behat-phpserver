<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use Behat\Config\Config;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class BehatConfigTest extends TestCase {

  /**
   * Test that no YAML configuration takes precedence over behat.php.
   *
   * @param string $file
   *   The configuration file name.
   */
  #[DataProvider('dataProviderNoYamlConfig')]
  public function testNoYamlConfig(string $file): void {
    $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/' . $file);
  }

  /**
   * Data provider for testNoYamlConfig().
   *
   * @return array<string, array{string}>
   *   Test cases.
   */
  public static function dataProviderNoYamlConfig(): array {
    return [
      'behat.yaml' => ['behat.yaml'],
      'behat.yml' => ['behat.yml'],
      'behat.yaml.dist' => ['behat.yaml.dist'],
      'behat.yml.dist' => ['behat.yml.dist'],
      'behat.dist.yaml' => ['behat.dist.yaml'],
      'behat.dist.yml' => ['behat.dist.yml'],
    ];
  }

  /**
   * Test that behat.dist.php sets every context constructor option.
   *
   * @param class-string $class
   *   The context class.
   */
  #[DataProvider('dataProviderDistSetsEveryOption')]
  public function testDistSetsEveryOption(string $class): void {
    $parameters = (new \ReflectionMethod($class, '__construct'))->getParameters();
    $expected = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);

    $this->assertSame($expected, array_keys(static::getDistContextOptions($class)));
  }

  /**
   * Data provider for testDistSetsEveryOption().
   *
   * @return array<string, array{class-string}>
   *   Test cases.
   */
  public static function dataProviderDistSetsEveryOption(): array {
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
  protected static function loadDistConfig(): array {
    $config = require dirname(__DIR__, 3) . '/behat.dist.php';

    if (!$config instanceof Config) {
      self::fail('behat.dist.php does not return a Behat configuration.');
    }

    return $config->toArray();
  }

  /**
   * Get the options that behat.dist.php sets for a context.
   *
   * @param class-string $class
   *   The context class.
   *
   * @return array<mixed>
   *   The options, keyed by name.
   */
  protected static function getDistContextOptions(string $class): array {
    $contexts = static::loadDistConfig();

    foreach (['default', 'suites', 'default', 'contexts'] as $key) {
      if (!is_array($contexts) || !isset($contexts[$key])) {
        self::fail(sprintf('behat.dist.php has no "%s" key on the path to the suite contexts.', $key));
      }

      $contexts = $contexts[$key];
    }

    if (!is_array($contexts)) {
      self::fail('behat.dist.php does not list the suite contexts.');
    }

    foreach ($contexts as $context) {
      if (is_array($context) && isset($context[$class]) && is_array($context[$class])) {
        return $context[$class];
      }
    }

    self::fail(sprintf('behat.dist.php does not configure %s.', $class));
  }

}
