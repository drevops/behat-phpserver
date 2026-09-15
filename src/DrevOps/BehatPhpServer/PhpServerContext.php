<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\ScenarioScope;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;

/**
 * Behat context to enable PHPServer support in tests.
 */
class PhpServerContext implements Context {

  /**
   * Tag for scenarios that require this server.
   */
  const TAG = 'phpserver';

  /**
   * Default connection retry timeout in seconds.
   */
  const DEFAULT_CONNECTION_TIMEOUT = 2;

  /**
   * Default retry delay in microseconds.
   */
  const DEFAULT_RETRY_DELAY = 100000;

  /**
   * Server process id.
   */
  protected int $pid = 0;

  /**
   * Connection retry timeout in seconds.
   */
  protected int $connectionTimeout;

  /**
   * Retry delay in microseconds.
   */
  protected int $retryDelay;

  /**
   * Constructs the PhpServerContext.
   *
   * @param string $webroot
   *   Webroot directory.
   * @param string $host
   *   Server hostname.
   * @param int $port
   *   Server port.
   * @param string $protocol
   *   Server protocol.
   * @param bool $debug
   *   Debug mode.
   * @param int|null $connection_timeout
   *   Connection retry timeout in seconds.
   * @param int|null $retry_delay
   *   Retry delay in microseconds.
   */
  public function __construct(
    protected string $webroot,
    protected string $host = '127.0.0.1',
    protected int $port = 8888,
    protected string $protocol = 'http',
    protected bool $debug = FALSE,
    ?int $connection_timeout = NULL,
    ?int $retry_delay = NULL,
  ) {
    if (!is_dir($this->webroot)) {
      throw new \RuntimeException(sprintf('"webroot" directory %s does not exist.', $this->webroot));
    }

    $this->connectionTimeout = $connection_timeout ?? static::DEFAULT_CONNECTION_TIMEOUT;
    $this->retryDelay = $retry_delay ?? static::DEFAULT_RETRY_DELAY;
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
   * Start the server before a scenario tagged for it.
   *
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope
   *   Scenario scope.
   */
  #[BeforeScenario]
  public function beforeScenarioStartServer(BeforeScenarioScope $scope): void {
    if ($this->isTagged($scope)) {
      $this->start();
    }
  }

  /**
   * Stop the server after a scenario tagged for it.
   *
   * @param \Behat\Behat\Hook\Scope\AfterScenarioScope $scope
   *   Scenario scope.
   */
  #[AfterScenario]
  public function afterScenarioStopServer(AfterScenarioScope $scope): void {
    if ($this->isTagged($scope)) {
      $this->stop();
    }
  }

  /**
   * Check whether a scenario is tagged for this server.
   *
   * Both the scenario and its feature are checked, so a tag on the feature
   * applies to every scenario in it.
   *
   * @param \Behat\Behat\Hook\Scope\ScenarioScope $scope
   *   Scenario scope.
   *
   * @return bool
   *   TRUE if the scenario or its feature carries the tag, FALSE otherwise.
   */
  protected function isTagged(ScenarioScope $scope): bool {
    $tags = array_merge($scope->getFeature()->getTags(), $scope->getScenario()->getTags());
    $tags = array_map(static fn(string $tag): string => ltrim($tag, '@'), $tags);

    return in_array(static::TAG, $tags, TRUE);
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
  public function start(): int {
    if (!$this->stop()) {
      throw new \RuntimeException(sprintf('Unable to stop existing server on port %d.', $this->port));
    }

    // The per-run timestamp is passed so the served scripts can read it.
    $command = sprintf('PROCESS_TIMESTAMP=%s php -S %s -t %s >/dev/null 2>&1 & echo $!', microtime(TRUE), escapeshellarg($this->host . ':' . $this->port), escapeshellarg($this->webroot));

    $this->printDebug(sprintf('Starting PHP server with command: %s', $command));

    $output = [];
    $code = 0;
    $success = $this->executeCommand($command, $output, $code);

    if (!$success || empty($output[0]) || !is_numeric($output[0])) {
      $this->printDebug(sprintf('Command execution failed with code %d or empty/invalid output: %s', $code, implode(', ', $output)));

      throw new \RuntimeException(sprintf('Unable to start PHP server: Command failed with code %d.', $code));
    }

    $this->pid = (int) $output[0];

    $this->printDebug(sprintf('PHP server started with PID %s.', $this->pid));

    if (!$this->isRunning()) {
      $this->stop();
      throw new \RuntimeException(sprintf('PHP server failed to start or accept connections within %d seconds.', $this->connectionTimeout));
    }

    $this->printDebug('PHP server is now running and accepting connections.');

    return $this->pid;
  }

  /**
   * Stop running server.
   *
   * @return bool
   *   TRUE if the port is free once the server is stopped, FALSE otherwise.
   */
  public function stop(): bool {
    if ($this->pid !== 0 && $this->processExists($this->pid)) {
      $this->printDebug(sprintf('Terminating known process with PID %d.', $this->pid));

      if ($this->terminateProcess($this->pid)) {
        $this->printDebug('Successfully terminated process.');
        $this->pid = 0;
      }
    }

    try {
      if ($this->isPortInUse($this->port)) {
        $this->printDebug(sprintf('Port %d is still in use. Attempting to free it.', $this->port));
        $port_freed = $this->freePort($this->port);

        if (!$port_freed) {
          $this->printDebug(sprintf('Failed to free port %d. Free port function returned failure.', $this->port));

          return FALSE;
        }

        if ($this->isPortInUse($this->port)) {
          $this->printDebug(sprintf('Failed to free port %d. Port is still in use after freeing attempt.', $this->port));

          return FALSE;
        }

        $this->printDebug(sprintf('Successfully freed port %d.', $this->port));
      }
      else {
        $this->printDebug(sprintf('Port %d is already free.', $this->port));
      }
    }
    catch (\Exception $exception) {
      $this->printDebug(sprintf('Error while trying to stop server: %s', $exception->getMessage()));

      return FALSE;
    }

    $this->pid = 0;

    return TRUE;
  }

  /**
   * Check that a server is running.
   *
   * @param int|null $timeout
   *   Retry timeout in seconds. If NULL, use the configured timeout.
   * @param int|null $retry_delay
   *   Delay between retries in microseconds. If NULL, use the configured delay.
   *
   * @return bool
   *   TRUE if the server is running, FALSE otherwise.
   */
  protected function isRunning(?int $timeout = NULL, ?int $retry_delay = NULL): bool {
    $timeout ??= $this->connectionTimeout;
    $retry_delay ??= $this->retryDelay;

    $start = microtime(TRUE);

    if ($this->pid > 0 && !$this->processExists($this->pid)) {
      return FALSE;
    }

    $counter = 1;

    while ((microtime(TRUE) - $start) <= $timeout) {
      $this->printDebug(sprintf('Checking if server is running. Attempt %s.', $counter));

      if ($this->canConnect()) {
        $this->printDebug('Server is running and accepting connections.');

        return TRUE;
      }

      usleep($retry_delay);
      $counter++;
    }

    $this->printDebug('Server is not responding to connection attempts.');

    return FALSE;
  }

  /**
   * Check if a port is already in use.
   *
   * @param int $port
   *   The port to check.
   *
   * @return bool
   *   TRUE if the port is in use, FALSE otherwise.
   */
  protected function isPortInUse(int $port): bool {
    $this->printDebug(sprintf('Checking if port %d is already in use.', $port));

    set_error_handler(static fn(): bool => TRUE);

    // A very short timeout avoids hanging.
    $connection = @fsockopen($this->host === '0.0.0.0' ? '127.0.0.1' : $this->host, $port, $errno, $errstr, 0.1);

    restore_error_handler();

    if ($connection !== FALSE) {
      fclose($connection);
      $this->printDebug(sprintf('Port %d is already in use (connection succeeded).', $port));

      return TRUE;
    }

    // Error 61 = Connection refused (macOS and BSD)
    // Error 111 = Connection refused (Linux)
    // Error 10061 = Connection refused (Windows)
    $connection_refused = in_array($errno, [61, 111, 10061], TRUE);

    if ($connection_refused) {
      $this->printDebug(sprintf('Port %d is available (connection refused).', $port));

      return FALSE;
    }

    // For any other errors, assume the port is in use to be safe.
    $this->printDebug(sprintf('Port %d status check resulted in error %d: %s. Assuming it is in use.', $port, $errno, $errstr));

    return TRUE;
  }

  /**
   * Attempt to free a port that's in use.
   *
   * @param int $port
   *   The port to free.
   *
   * @return bool
   *   TRUE if the port is free after the process holding it is terminated,
   *   FALSE otherwise.
   */
  protected function freePort(int $port): bool {
    $this->printDebug(sprintf('Attempting to free port %d.', $port));

    try {
      $pid = $this->getPid($port);

      $this->printDebug(sprintf('Found process with PID %d using port %d.', $pid, $port));
      $this->terminateProcess($pid);

      if ($this->isPortInUse($port)) {
        $this->printDebug(sprintf('Port %d is still in use after terminating process %d.', $port, $pid));

        return FALSE;
      }

      return TRUE;
    }
    catch (\Exception $exception) {
      $this->printDebug(sprintf('Error while trying to free port %d: %s', $port, $exception->getMessage()));

      return FALSE;
    }
  }

  /**
   * Check if it is possible to connect to a running PHP server.
   *
   * @param int|null $timeout
   *   Connection timeout in seconds. If NULL, use the configured timeout.
   *
   * @return bool
   *   TRUE if PHP server is running, and it is possible to connect to it via
   *   socket, FALSE otherwise.
   */
  protected function canConnect(?int $timeout = NULL): bool {
    $timeout ??= $this->connectionTimeout;

    set_error_handler(static fn(): bool => TRUE);

    $connection = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout);

    restore_error_handler();

    if ($connection === FALSE) {
      $this->printDebug(sprintf('Unable to connect to the server. Error: %s (%s)', $errstr, $errno));

      return FALSE;
    }

    fclose($connection);

    $this->printDebug('Connected to the server.');

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
    $this->printDebug(sprintf('Terminating PHP server process with PID %s.', $pid));

    if (!$this->processExists($pid)) {
      $this->printDebug(sprintf('Process with PID %d does not exist, no need to terminate.', $pid));

      return TRUE;
    }

    $output = [];
    $success = $this->executeCommand('kill ' . $pid . ' 2>/dev/null', $output);
    $termination_status = 'graceful';

    if (!$success) {
      $this->printDebug('Graceful termination failed, trying forceful termination (SIGKILL).');
      $success = $this->executeCommand('kill -9 ' . $pid . ' 2>/dev/null', $output);
      $termination_status = $success ? 'forceful' : 'failed';
    }

    // The process needs a short time to terminate.
    usleep($this->retryDelay);

    if ($this->processExists($pid)) {
      $this->printDebug(sprintf('Process termination verification failed (%s termination status), process may still be running.', $termination_status));

      return FALSE;
    }

    $this->printDebug(sprintf('Process terminated successfully with %s termination.', $termination_status));

    return $success;
  }

  /**
   * Check if a process exists.
   *
   * @param int $pid
   *   Process id to check.
   *
   * @return bool
   *   TRUE if the process exists, FALSE otherwise.
   */
  protected function processExists(int $pid): bool {
    if ($pid <= 0) {
      return FALSE;
    }

    $output = [];
    $this->executeCommand('ps -p ' . $pid . ' 2>/dev/null', $output);
    $is_running = count($output) > 1;

    if (!$is_running) {
      $this->printDebug(sprintf('Process with PID %d is not running.', $pid));
    }

    return $is_running;
  }

  /**
   * Get PID of the running server on the specified port.
   *
   * The retrieved PID may belong to a process that was started by another
   * process rather than the current one.
   *
   * @param int $port
   *   Port number.
   *
   * @return int
   *   PID as number.
   *
   * @throws \RuntimeException
   *   If no process can be identified on the port.
   */
  protected function getPid(int $port): int {
    $this->printDebug(sprintf('Finding PID of the PHP server process on port %s.', $port));

    if ($this->pid > 0 && $this->processExists($this->pid)) {
      $this->printDebug(sprintf('Found existing process with PID %s is still running.', $this->pid));

      return $this->pid;
    }

    $pid = $this->getPidLsof($port);

    if ($pid === 0) {
      $pid = $this->getPidNetstat($port);
    }

    if ($pid === 0) {
      $this->printDebug('Could not identify PHP process using lsof or netstat.');
      throw new \RuntimeException(sprintf('Unable to determine PHP server process for port %d. Manually identify the process and terminate it.', $port));
    }

    return $pid;
  }

  /**
   * Get PID of the running server on the specified port using lsof.
   *
   * @param int $port
   *   Port number.
   *
   * @return int
   *   PID as number.
   */
  protected function getPidLsof(int $port): int {
    $command = sprintf("lsof -i -P -n 2>/dev/null | grep 'php' | grep ':%s' | grep 'LISTEN'", $port);

    foreach ($this->listPortProcesses('lsof', $command, $port) as $parts) {
      // Accept any executable that starts with "php" (php, php-fpm, php8.3).
      if (count($parts) > 1 && str_starts_with($parts[0], 'php') && is_numeric($parts[1])) {
        $pid = (int) $parts[1];
        $this->printDebug(sprintf('Found PHP process with PID %s using lsof.', $pid));

        return $pid;
      }
    }

    return 0;
  }

  /**
   * Get PID of the running server on the specified port using netstat.
   *
   * @param int $port
   *   Port number.
   *
   * @return int
   *   PID as number.
   */
  protected function getPidNetstat(int $port): int {
    // -p is only available on Linux.
    $command = sprintf("netstat -anp 2>/dev/null | grep ':%s' | grep 'LISTEN'", $port);

    foreach ($this->listPortProcesses('netstat', $command, $port) as $parts) {
      foreach ($parts as $part) {
        if (!str_contains($part, '/php')) {
          continue;
        }

        $pid_name_parts = explode('/', $part);

        if (count($pid_name_parts) < 2) {
          continue;
        }

        $found_pid = $pid_name_parts[0];
        $name = $pid_name_parts[1];

        if (!is_numeric($found_pid) || !str_starts_with($name, 'php')) {
          continue;
        }

        $pid = (int) $found_pid;
        $this->printDebug(sprintf('Found PHP process with PID %s using netstat.', $pid));

        return $pid;
      }
    }

    return 0;
  }

  /**
   * List the processes on a port with a port listing tool.
   *
   * @param string $tool
   *   The listing tool that the command runs.
   * @param string $command
   *   The command that lists the processes listening on the port.
   * @param int $port
   *   Port number.
   *
   * @return array<int, array<int, string>>
   *   The whitespace-separated fields of each output line, or an empty array
   *   when the tool is not installed or lists no process.
   */
  protected function listPortProcesses(string $tool, string $command, int $port): array {
    if (!$this->executeCommand('which ' . $tool . ' 2>/dev/null')) {
      return [];
    }

    // The grep filter matches the port as a substring, so ':80' also matches
    // ':8080'.
    $port_pattern = '/:' . $port . '(?!\d)/';

    $output = [];
    $this->executeCommand($command, $output);
    $lines = preg_grep($port_pattern, $output) ?: [];

    if ($lines === []) {
      // The process may be in another state, so retry without the LISTEN
      // filter.
      $command = str_replace(" | grep 'LISTEN'", '', $command);
      $this->printDebug(sprintf('No LISTEN processes found, retrying with command: %s', $command));

      $output = [];
      $this->executeCommand($command, $output);
      $lines = preg_grep($port_pattern, $output) ?: [];
    }

    if ($lines === []) {
      $this->printDebug(sprintf('No processes found on port %d', $port));

      return [];
    }

    $processes = [];

    foreach (array_values($lines) as $i => $line) {
      $this->printDebug(sprintf('Found process %d: %s', $i + 1, $line));
      $processes[] = explode(' ', trim((string) preg_replace('/\s+/', ' ', $line)));
    }

    return $processes;
  }

  /**
   * Execute a shell command and report whether it succeeded.
   *
   * @param string $command
   *   The command to execute.
   * @param array<string> $output
   *   An array that will be filled with the output of the command.
   * @param int $code
   *   The return status of the executed command.
   *
   * @return bool
   *   TRUE if the command was executed successfully, FALSE otherwise.
   */
  protected function executeCommand(string $command, array &$output = [], int &$code = 0): bool {
    // @codeCoverageIgnoreStart
    exec($command, $output, $code);

    return !$code;
    // @codeCoverageIgnoreEnd
  }

  /**
   * Print debug message if debug mode is enabled.
   *
   * @param string $message
   *   Message to print.
   */
  protected function printDebug(string $message): void {
    // @codeCoverageIgnoreStart
    if ($this->debug) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
      $caller = $backtrace[1]['function'] ?? 'unknown';
      print sprintf('[%s()] %s', $caller, $message) . PHP_EOL;
    }
    // @codeCoverageIgnoreEnd
  }

}
