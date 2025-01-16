<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

/**
 * Class PhpServerContext.
 *
 * Behat context to enable PHPServer support in tests.
 *
 * @package DrevOps\BehatPhpServer
 */
class PhpServerContext implements Context {

  /**
   * Docroot directory.
   *
   * @var string
   */
  protected $docroot;

  /**
   * Server hostname.
   *
   * @var string
   */
  protected $host;

  /**
   * Server port.
   *
   * @var string
   */
  protected $port;

  /**
   * Server process id.
   *
   * @var int
   */
  protected $pid;

  /**
   * PhpServerTrait constructor.
   *
   * @param mixed[] $parameters
   *   Settings for server.
   */
  public function __construct(array $parameters = []) {
    $this->docroot = $parameters['docroot'] ?? __DIR__ . '/fixtures';
    if (!file_exists($this->docroot)) {
      throw new \RuntimeException(sprintf('"docroot" directory %s does not exist', $this->docroot));
    }
    $this->host = $parameters['host'] ?? 'localhost';
    $this->port = $parameters['port'] ?? '8888';
  }

  /**
   * Start server before each scenario.
   *
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   *   Scenario scope.
   *
   * @beforeScenario @phpserver
   */
  public function beforeScenarioStartPhpServer(BeforeScenarioScope $scope): void {
    if ($scope->getScenario()->hasTag('phpserver')) {
      $this->start();
    }
  }

  /**
   * Stop server after each scenario.
   *
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   *   Scenario scope.
   *
   * @afterScenario @phpserver
   */
  public function afterScenarioStopPhpServer(AfterScenarioScope $scope): void {
    if ($scope->getScenario()->hasTag('phpserver')) {
      $this->stop();
    }
  }

  /**
   * Start a server.
   *
   * @return int
   *   PID as number.
   *
   * @throws \RuntimeException
   *   If unable to start a server.
   */
  protected function start(): int {
    // If the server already running on this port, stop it.
    // This is a much simpler way of handling previously started servers than
    // having a server manager that would track each instance.
    if ($this->isRunning(FALSE)) {
      $pid = $this->getPid($this->port);
      $this->terminateProcess($pid);
    }

    $command = sprintf(
          'php -S %s:%d -t %s >/dev/null 2>&1 & echo $!',
          $this->host,
          $this->port,
          $this->docroot
      );

    $output = [];
    $code = 0;
    exec($command, $output, $code);
    if ($code === 0) {
      $this->pid = (int) $output[0];
    }

    if (!$this->pid || !$this->isRunning()) {
      throw new \RuntimeException('Unable to start PHP server');
    }

    return (int) $this->pid;
  }

  /**
   * Stop running server.
   *
   * @return bool
   *   TRUE if server process was stopped, FALSE otherwise.
   */
  protected function stop(): bool {
    if (!$this->isRunning(FALSE)) {
      return TRUE;
    }

    return $this->terminateProcess($this->pid);
  }

  /**
   * Check that a server is running.
   *
   * @param int|bool $timeout
   *   Retry timeout in seconds.
   * @param int $delay
   *   Delay between retries in microseconds.
   *   Default to 0.5 of the second.
   *
   * @return bool
   *   TRUE if the server is running, FALSE otherwise.
   */
  protected function isRunning($timeout = 1, $delay = 500000): bool {
    if ($timeout === FALSE) {
      return $this->canConnect();
    }

    $start = microtime(TRUE);

    while ((microtime(TRUE) - $start) <= $timeout) {
      if ($this->canConnect()) {
        return TRUE;
      }

      usleep($delay);
    }

    return FALSE;
  }

  /**
   * Check if it is possible to connect to a server.
   *
   * @return bool
   *   TRUE if server is running and it is possible to connect to it via
   *   socket, FALSE otherwise.
   */
  protected function canConnect(): bool {
    set_error_handler(
          static function () : bool {
              return TRUE;
          }
      );

    $sp = fsockopen($this->host, (int) $this->port);

    restore_error_handler();

    if ($sp === FALSE) {
      return FALSE;
    }

    fclose($sp);

    return TRUE;
  }

  /**
   * Terminate a process.
   *
   * @param int $pid
   *   Process id.
   *
   * @return bool
   *   TRUE if the process was successfully terminated, FALSE otherwise.
   */
  protected function terminateProcess($pid): bool {
    // If pid was not provided, do not allow to terminate current process.
    if (!$pid) {
      return TRUE;
    }

    $output = [];
    $code = 0;
    exec('kill ' . (int) $pid, $output, $code);

    return $code === 0;
  }

  /**
   * Get PID of the running server on the specified port.
   *
   * Note that this will retrieve a PID of the process that could have been
   * started by another process rather then current one.
   *
   * @param string $port
   *   Port number.
   *
   * @return int
   *   PID as number.
   */
  protected function getPid($port): int {
    $pid = 0;

    $output = [];
    // @todo Add support to OSes other then OSX and Ubuntu.
    exec(sprintf("netstat -peanut 2>/dev/null|grep ':%s'", $port), $output);

    if (!isset($output[0])) {
      throw new \RuntimeException(
            'Unable to determine if PHP server was started on current OS.'
        );
    }
    $outputIndexZeroReplaced = preg_replace('/\s+/', ' ', $output[0]);
    $parts = [];
    if (!empty($outputIndexZeroReplaced)) {
      $parts = explode(' ', $outputIndexZeroReplaced);
    }

    if (isset($parts[8]) && $parts[8] !== '-') {
      [$pid, $name] = explode('/', $parts[8]);
      if ($name !== 'php') {
        $pid = 0;
      }
    }

    return (int) $pid;
  }

}
