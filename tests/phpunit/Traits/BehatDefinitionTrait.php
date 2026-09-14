<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Traits;

/**
 * Provides methods to inspect how a context declares Behat definitions.
 *
 * @phpstan-ignore trait.unused
 */
trait BehatDefinitionTrait {

  /**
   * Get the attributes declared on a method.
   *
   * @param class-string $class
   *   Class that declares the method.
   * @param string $method
   *   Method name.
   *
   * @return array<int, array{0: string, 1: array<int|string, mixed>}>
   *   Attribute class names paired with their arguments, in declaration order.
   */
  protected static function getMethodAttributes(string $class, string $method): array {
    $attributes = (new \ReflectionMethod($class, $method))->getAttributes();

    return array_map(static fn(\ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $attributes);
  }

  /**
   * Get the Behat annotations on the methods a class declares itself.
   *
   * Step, hook and transformation annotations are matched the way Behat 3
   * reads them: case-insensitively, at the start of a docblock line.
   *
   * @param class-string $class
   *   Class to inspect.
   *
   * @return array<string, array<int, string>>
   *   Annotation lines keyed by method name.
   */
  protected static function getBehatAnnotations(string $class): array {
    $annotations = [];

    foreach ((new \ReflectionClass($class))->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() !== $class) {
        continue;
      }

      preg_match_all('/^\s*\*\s*(@(?:given|when|then|transform|(?:before|after)(?:suite|feature|scenario|step))\b.*)$/im', (string) $method->getDocComment(), $matches);

      if (!empty($matches[1])) {
        $annotations[$method->getName()] = $matches[1];
      }
    }

    return $annotations;
  }

}
