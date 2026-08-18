<?php

/**
 * @file
 * Rector configuration.
 *
 * Usage:
 * ./vendor/bin/rector process .
 */

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
  ->withPaths([
    __DIR__ . '/**',
  ])
  ->withPhpSets(php82: TRUE)
  ->withPreparedSets(
    deadCode: TRUE,
    codeQuality: TRUE,
    codingStyle: TRUE,
    typeDeclarations: TRUE,
    instanceOf: TRUE,
  )
  ->withRules([
    DeclareStrictTypesRector::class,
  ])
  ->withSkip([
    // Promotion rewrites the documented properties of the anonymous test
    // classes into the constructor signature, which moves their docblocks
    // inside the parameter list and leaves PHPCBF unable to format the result.
    ClassPropertyAssignToConstructorPromotionRector::class => [
      __DIR__ . '/tests',
    ],
    // Rules added by Rector's rule sets.
    CatchExceptionNameMatchingTypeRector::class,
    ChangeSwitchToMatchRector::class,
    CompleteDynamicPropertiesRector::class,
    InlineArrayReturnAssignRector::class,
    NewlineAfterStatementRector::class,
    NewlineBeforeNewAssignSetRector::class,
    NewlineBetweenClassLikeStmtsRector::class,
    RemoveAlwaysTrueIfConditionRector::class,
    SimplifyEmptyCheckOnEmptyArrayRector::class,
    // Dependencies.
    '*/vendor/*',
    '*/node_modules/*',
  ])
  ->withFileExtensions([
    'php',
    'inc',
  ])
  ->withImportNames(importNames: TRUE, importDocBlockNames: FALSE, importShortClasses: FALSE);
