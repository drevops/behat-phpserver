<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use DrevOps\BehatPhpServer\ApiServerContext;
use DrevOps\BehatPhpServer\PhpServerContext;

$suite = (new Suite('default'))
  ->addContext(PhpServerContext::class, [
    'webroot' => '%paths.base%/tests/behat/fixtures',
    'host' => '127.0.0.1',
    'port' => 8888,
    'protocol' => 'http',
    'debug' => FALSE,
    'connection_timeout' => 2,
    'retry_delay' => 100000,
  ])
  ->addContext(ApiServerContext::class, [
    'webroot' => '%paths.base%/vendor/drevops/behat-phpserver/apiserver',
    'host' => '127.0.0.1',
    'port' => 8889,
    'protocol' => 'http',
    'debug' => FALSE,
    'connection_timeout' => 2,
    'retry_delay' => 100000,
    'paths' => ['%paths.base%/tests/behat/fixtures', '%paths.base%/tests/behat/fixtures2'],
  ]);

return (new Config())->withProfile((new Profile('default'))->withSuite($suite));
