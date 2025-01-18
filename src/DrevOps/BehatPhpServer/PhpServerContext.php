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
   * Tag for scenarios that require this server.
   */
  const TAG = 'phpserver';

  /**
   * Default webroot directory.
   */
  const DEFAULT_WEBROOT = __DIR__ . '/fixtures';

  /**
   * Webroot directory.
   */
  protected string $webroot;

  /**
   * Server hostname.
   */
  protected string $host;

  /**
   * Server port.
   */
  protected int $port;

  /**
   * Server protocol.
   */
  protected string $protocol;

  /**
   * Server process id.
   */
  protected int $pid = 0;

  /**
   * Debug mode.
   */
  protected bool $debug = FALSE;

  /**
   * PhpServerTrait constructor.
   *
   * @param string|null $webroot
   *   Webroot directory.
   * @param string $host
   *   Server hostname.
   * @param int $port
   *   Server port.
   * @param string $protocol
   *   Server protocol.
   * @param bool $debug
   *   Debug mode.
   */
  public function __construct(?string $webroot = NULL, string $host = '127.0.0.1', int $port = 8888, string $protocol = 'http', bool $debug = FALSE) {
    $this->webroot = $webroot ?: static::DEFAULT_WEBROOT;

    if (!file_exists($this->webroot)) {
      throw new \RuntimeException(sprintf('"webroot" directory %s does not exist', $this->webroot));
    }

    $this->host = $host;
    $this->port = $port;
    $this->protocol = $protocol;
    $this->debug = $debug;
  }

  /**
   * Get server URL.
   *
   * @return string
   *   Server URL.
   */
  public function getServerUrl(): string {
    return $this->protocol . '://' . $this->host . ':' . $this->port;
  }

  /**
   * Start server before each scenario.
   *
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   *   Scenario scope.
   *
   * @beforeScenario
   */
  public function beforeScenarioStartServer(BeforeScenarioScope $scope): void {
    if ($scope->getScenario()->hasTag(static::TAG)) {
      $this->start();
    }
  }

  /**
   * Stop server after each scenario.
   *
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   *   Scenario scope.
   *
   * @afterScenario
   */
  public function afterScenarioStopServer(AfterScenarioScope $scope): void {
    if ($scope->getScenario()->hasTag(static::TAG)) {
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
    $this->stop();

    $command = sprintf(
    // Pass a random process ID to the server so it can be accessed from
    // within the scripts.
      'PROCESS_TIMESTAMP=%s php -S %s:%d -t %s >/dev/null 2>&1 & echo $!',
      microtime(TRUE),
      $this->host,
      $this->port,
      $this->webroot
    );

    $this->debug(sprintf('Starting PHP server with command: %s', $command));

    $output = [];
    $code = 0;
    exec($command, $output, $code);
    if ($code === 0) {
      $this->pid = (int) $output[0];
    }

    $this->debug(sprintf('PHP server started with PID %s.', $this->pid));

    if ($this->pid === 0) {
      throw new \RuntimeException('Unable to start PHP server: PID is 0');
    }

    if (!$this->isRunning()) {
      throw new \RuntimeException('PHP server is not running');
    }

    // Despite isRunning() check, the server may not be ready to accept
    // connections immediately after starting, so we wait a bit.
    usleep(500000);

    return $this->pid;
  }

  /**
   * Stop running server.
   *
   * @return bool
   *   TRUE if server process was stopped, FALSE otherwise.
   */
  protected function stop(): bool {
    if ($this->pid === 0) {
      $this->debug('PID is 0, server is not running.');

      return TRUE;
    }

    if (!$this->isRunning()) {
      $this->pid = 0;

      return TRUE;
    }

    try {
      $this->pid = $this->getPid($this->port);
      $this->terminateProcess($this->pid);
      $this->pid = 0;
    }
    catch (\RuntimeException $runtimeException) {
      print $runtimeException->getMessage();

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Check that a server is running.
   *
   * @param int $timeout
   *   Retry timeout in seconds.
   * @param int $retry
   *   Delay between retries in microseconds.
   *
   * @return bool
   *   TRUE if the server is running, FALSE otherwise.
   */
  protected function isRunning(int $timeout = 1, int $retry = 100000): bool {
    $start = microtime(TRUE);

    $counter = 1;
    while ((microtime(TRUE) - $start) <= $timeout) {
      $this->debug(sprintf('Checking if server is running. Attempt %s.', $counter));

      if ($this->canConnect()) {
        $this->debug('Server is running.');

        return TRUE;
      }

      usleep($retry);
      $counter++;
    }

    $this->debug('Server is not running.');

    return FALSE;
  }

  /**
   * Check if it is possible to connect to a running PHP server.
   *
   * @return bool
   *   TRUE if PHP server is running and it is possible to connect to it via
   *   socket, FALSE otherwise.
   */
  protected function canConnect(): bool {
    set_error_handler(
      static function (): bool {
        return TRUE;
      }
    );

    $sp = fsockopen($this->host, $this->port);

    restore_error_handler();

    if ($sp === FALSE) {
      $this->debug('Unable to connect to the server.');

      return FALSE;
    }

    fclose($sp);

    $this->debug('Connected to the server.');

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
  protected function terminateProcess(int $pid): bool {
    $output = [];
    $code = 0;

    $this->debug(sprintf('Terminating PHP server process with PID %s.', $pid));

    exec('kill ' . $pid, $output, $code);

    return $code === 0;
  }

  /**
   * Get PID of the running server on the specified port.
   *
   * Note that this will retrieve a PID of the process that could have been
   * started by another process rather then current one.
   *
   * @param int $port
   *   Port number.
   *
   * @return int
   *   PID as number.
   */
  protected function getPid(int $port): int {
    $pid = 0;

    $output = [];

    $type = NULL;
    $command = NULL;

    $this->debug(sprintf('Finding PID of the PHP server process on port %s.', $port));

    if (shell_exec('which lsof')) {
      $command = sprintf("lsof -i -P -n 2>/dev/null | grep 'php' | grep ':%s'", $port);
      $type = 'lsof';
    }
    elseif (shell_exec('which netstat')) {
      $command = sprintf("netstat -peanut 2>/dev/null | grep ':%s'", $port);
      $type = 'netstat';
    }

    $this->debug(sprintf('Using "%s" command to find the PID.', $command));

    if (empty($command)) {
      throw new \RuntimeException('Unable to determine if PHP server was started: no supported OS utilities found. Manually identify the process and terminate it.');
    }

    exec($command, $output);

    if (!isset($output[0])) {
      throw new \RuntimeException(sprintf('Unable to determine if PHP server was started: command "%s" returned output "%s". Manually identify the process and terminate it.', $command, implode("\n", $output)));
    }

    $output[0] = preg_replace('/\s+/', ' ', $output[0]);

    $this->debug(sprintf('Command output: %s', $output[0]));

    $parts = [];
    if (!empty($output[0])) {
      $parts = explode(' ', $output[0]);
    }

    if ($type === 'lsof') {
      if (($parts[0] ?? '') === 'php' && is_numeric($parts[1] ?: '')) {
        $pid = intval($parts[1]);
      }
    }
    elseif ($type === 'netstat') {
      if (isset($parts[8]) && $parts[8] !== '-') {
        [$pid, $name] = explode('/', $parts[8]);
        $pid = $name !== 'php' ? 0 : intval($pid);
      }
    }

    if ($pid === 0) {
      throw new \RuntimeException(sprintf('Unable to determine if PHP server was started: PID is 0 in command "%s" returned output "%s". Manually identify the process and terminate it.', $command, implode("\n", $output)));
    }

    return $pid;
  }

  /**
   * Print debug message if debug mode is enabled.
   *
   * @param string $message
   *   Message to print.
   */
  protected function debug(string $message): void {
    if ($this->debug) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
      $caller = $backtrace[1]['function'] ?? 'unknown';
      print sprintf('[%s()] %s', $caller, $message) . PHP_EOL;
    }
  }

}
