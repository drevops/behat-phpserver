<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Filter\TagFilter;
use Behat\Config\Formatter\JUnitFormatter;
use Behat\Config\Formatter\PrettyFormatter;
use Behat\Config\GherkinOptions;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\Config\TesterOptions;
use Behat\MinkExtension\ServiceContainer\MinkExtension;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;
use DVDoug\Behat\CodeCoverage\Extension as CodeCoverageExtension;

$suite = (new Suite('default'))
  ->withPaths('%paths.base%/tests/behat/features')
  ->addContext(PhpServerContext::class, [
    'webroot' => '%paths.base%/tests/behat/fixtures',
    'protocol' => 'http',
    'host' => '0.0.0.0',
    'port' => 8888,
    'debug' => TRUE,
  ])
  ->addContext(ApiServerContext::class, [
    'webroot' => '%paths.base%/apiserver',
    'protocol' => 'http',
    'host' => '0.0.0.0',
    'port' => 8889,
    'debug' => TRUE,
    'paths' => ['%paths.base%/tests/behat/fixtures', '%paths.base%/tests/behat/fixtures2'],
  ])
  ->addContext('FeatureContext');

$profile = (new Profile('default', ['autoload' => ['%paths.base%/tests/behat/bootstrap']]))
  ->withGherkinOptions((new GherkinOptions())->withFilter(new TagFilter('~@skipped')))
  ->withTesterOptions((new TesterOptions())->withStrictResultInterpretation())
  ->withSuite($suite)
  ->withExtension(new Extension(MinkExtension::class, ['sessions' => ['default' => ['browserkit_http' => NULL]]]))
  ->withExtension(new Extension(CodeCoverageExtension::class, [
    'filter' => ['include' => ['directories' => ['%paths.base%/src' => NULL]]],
    'reports' => [
      'text' => ['showColors' => TRUE, 'showOnlySummary' => TRUE],
      'html' => ['target' => '%paths.base%/.logs/behat/.coverage-html'],
      'cobertura' => ['target' => '%paths.base%/.logs/behat/cobertura.xml'],
    ],
  ]))
  ->withFormatter(new PrettyFormatter())
  ->withFormatter((new JUnitFormatter())->withOutputPath('%paths.base%/.logs/behat/test_results'));

return (new Config())->withProfile($profile);
