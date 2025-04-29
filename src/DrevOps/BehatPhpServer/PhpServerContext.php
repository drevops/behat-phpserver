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
   * Default connection retry timeout in seconds.
   */
  const DEFAULT_CONNECTION_TIMEOUT = 2;

  /**
   * Default retry delay in microseconds.
   */
  const DEFAULT_RETRY_DELAY = 100000;

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
   * Connection retry timeout in seconds.
   */
  protected int $connectionTimeout;

  /**
   * Retry delay in microseconds.
   */
  protected int $retryDelay;

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
   * @param int|null $connection_timeout
   *   Connection retry timeout in seconds.
   * @param int|null $retry_delay
   *   Retry delay in microseconds.
   */
  public function __construct(
    ?string $webroot = NULL,
    string $host = '127.0.0.1',
    int $port = 8888,
    string $protocol = 'http',
    bool $debug = FALSE,
    ?int $connection_timeout = NULL,
    ?int $retry_delay = NULL,
  ) {
    $this->webroot = $webroot ?: static::DEFAULT_WEBROOT;

    if (!file_exists($this->webroot)) {
      throw new \RuntimeException(sprintf('"webroot" directory %s does not exist', $this->webroot));
    }

    $this->host = $host;
    $this->port = $port;
    $this->protocol = $protocol;
    $this->debug = $debug;
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
  public function start(): int {
    // Make sure any existing server is stopped.
    if (!$this->stop()) {
      throw new \RuntimeException(sprintf('Unable to stop existing server on port %d.', $this->port));
    }

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
    $success = $this->executeCommand($command, $output, $code);
    if ($success && !empty($output[0]) && is_numeric($output[0])) {
      $this->pid = (int) $output[0];
    }
    else {
      $this->debug(sprintf('Command execution failed with code %d or empty/invalid output: %s', $code, implode(', ', $output)));
      throw new \RuntimeException(sprintf('Unable to start PHP server: Command failed with code %d', $code));
    }

    $this->debug(sprintf('PHP server started with PID %s.', $this->pid));

    if (!$this->isRunning()) {
      $this->stop();
      throw new \RuntimeException(sprintf(
        'PHP server failed to start or accept connections within %d seconds.',
        $this->connectionTimeout
      ));
    }

    $this->debug('PHP server is now running and accepting connections.');
    return $this->pid;
  }

  /**
   * Stop running server.
   *
   * @return bool
   *   TRUE if server process was stopped, FALSE otherwise.
   */
  public function stop(): bool {
    if ($this->pid !== 0 && $this->processExists($this->pid)) {
      $this->debug(sprintf('Terminating known process with PID %d.', $this->pid));
      if ($this->terminateProcess($this->pid)) {
        $this->debug('Successfully terminated process.');
        $this->pid = 0;
      }
    }

    try {
      // Check if port is still in use.
      if ($this->isPortInUse($this->port)) {
        $this->debug(sprintf('Port %d is still in use. Attempting to free it.', $this->port));
        $port_freed = $this->freePort($this->port);

        if (!$port_freed) {
          $this->debug(sprintf('Failed to free port %d. Free port function returned failure.', $this->port));
          return FALSE;
        }

        // Double-check the port is actually free now.
        if ($this->isPortInUse($this->port)) {
          $this->debug(sprintf('Failed to free port %d. Port is still in use after freeing attempt.', $this->port));
          return FALSE;
        }

        $this->debug(sprintf('Successfully freed port %d.', $this->port));
      }
      else {
        $this->debug(sprintf('Port %d is already free.', $this->port));
      }
    }
    catch (\Exception $exception) {
      $this->debug(sprintf('Error while trying to stop server: %s', $exception->getMessage()));
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
    $timeout = $timeout ?? $this->connectionTimeout;
    $retry_delay = $retry_delay ?? $this->retryDelay;

    $start = microtime(TRUE);

    // First, if we have a PID, check if the process is actually running.
    if ($this->pid > 0 && !$this->processExists($this->pid)) {
      return FALSE;
    }

    // Next, try to make a connection to verify server is accepting connections.
    $counter = 1;
    while ((microtime(TRUE) - $start) <= $timeout) {
      $this->debug(sprintf('Checking if server is running. Attempt %s.', $counter));

      if ($this->canConnect()) {
        $this->debug('Server is running and accepting connections.');
        return TRUE;
      }

      usleep($retry_delay);
      $counter++;
    }

    $this->debug('Server is not responding to connection attempts.');
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
    $this->debug(sprintf('Checking if port %d is already in use.', $port));

    // Temporarily suppress errors.
    set_error_handler(static function (): bool {
      return TRUE;
    });

    // Use very short timeout to avoid hanging.
    $connection = @fsockopen(
      $this->host === '0.0.0.0' ? '127.0.0.1' : $this->host,
      $port,
      $errno,
      $errstr,
      0.1
    );

    // Restore error handler.
    restore_error_handler();

    // If connection succeeded, the port is in use.
    if ($connection !== FALSE) {
      fclose($connection);
      $this->debug(sprintf('Port %d is already in use (connection succeeded).', $port));
      return TRUE;
    }

    // If connection failed because the port is unreachable, it's not in use.
    // Error 111 = Connection refused (Linux)
    // Error 10061 = Connection refused (Windows)
    $connection_refused = in_array($errno, [61, 111, 10061], TRUE);

    if ($connection_refused) {
      $this->debug(sprintf('Port %d is available (connection refused).', $port));
      return FALSE;
    }

    // For any other errors, assume the port is in use to be safe.
    $this->debug(sprintf('Port %d status check resulted in error %d: %s. Assuming it is in use.', $port, $errno, $errstr));
    return TRUE;
  }

  /**
   * Attempt to free a port that's in use.
   *
   * @param int $port
   *   The port to free.
   *
   * @return bool
   *   TRUE if the port was successfully freed or no process was found,
   *   FALSE if there was an error or the process could not be terminated.
   */
  protected function freePort(int $port): bool {
    $this->debug(sprintf('Attempting to free port %d.', $port));

    try {
      $pid = $this->getPid($port);
      if ($pid > 0) {
        $this->debug(sprintf('Found process with PID %d using port %d.', $pid, $port));
        $result = $this->terminateProcess($pid);

        // Verify the port is now free.
        $is_free = !$this->isPortInUse($port);

        if (!$is_free) {
          $this->debug(sprintf('Port %d is still in use after terminating process %d.', $port, $pid));
          return FALSE;
        }

        return $result;
      }

      // No process found, consider the port free.
      return TRUE;
    }
    catch (\Exception $exception) {
      $this->debug(sprintf('Error while trying to free port %d: %s', $port, $exception->getMessage()));
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
    $timeout = $timeout ?? $this->connectionTimeout;

    set_error_handler(
      static function (): bool {
        return TRUE;
      }
    );

    // Use timeout to avoid hanging connections.
    $sp = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout);

    restore_error_handler();

    if ($sp === FALSE) {
      $this->debug(sprintf('Unable to connect to the server. Error: %s (%s)', $errstr, $errno));
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
    $termination_status = 'unknown';

    $this->debug(sprintf('Terminating PHP server process with PID %s.', $pid));

    // First check if the process exists.
    if (!$this->processExists($pid)) {
      $this->debug(sprintf('Process with PID %d does not exist, no need to terminate.', $pid));
      return TRUE;
    }

    $output = [];
    // First try graceful termination (SIGTERM).
    $success = $this->executeCommand('kill ' . $pid . ' 2>/dev/null', $output);

    if (!$success) {
      $this->debug('Graceful termination failed, trying forceful termination (SIGKILL).');
      // If the first attempt fails, try force kill.
      $success = $this->executeCommand('kill -9 ' . $pid . ' 2>/dev/null', $output);
      $termination_status = $success ? 'forceful' : 'failed';
    }
    else {
      $termination_status = 'graceful';
    }

    // Wait a short time for the process to terminate.
    usleep($this->retryDelay);

    // Verify process is actually gone.
    if ($this->processExists($pid)) {
      $this->debug(sprintf(
        'Process termination verification failed (%s termination status), process may still be running.',
        $termination_status
      ));
      return FALSE;
    }

    $this->debug(sprintf('Process terminated successfully with %s termination.', $termination_status));
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
      $this->debug(sprintf('Process with PID %d is not running.', $pid));
    }

    return $is_running;
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
    $this->debug(sprintf('Finding PID of the PHP server process on port %s.', $port));

    // First, try with the stored PID if we have one.
    if ($this->pid > 0 && $this->processExists($this->pid)) {
      $this->debug(sprintf('Found existing process with PID %s is still running.', $this->pid));
      return $this->pid;
    }

    $pid = $this->getPidLsof($port);
    if ($pid === 0) {
      $pid = $this->getPidNetstat($port);
    }

    if ($pid === 0) {
      $this->debug('Could not identify PHP process using lsof or netstat.');
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
    if (!$this->executeCommand('which lsof 2>/dev/null')) {
      return 0;
    }

    $command = sprintf("lsof -i -P -n 2>/dev/null | grep 'php' | grep ':%s' | grep 'LISTEN'", $port);

    $output = [];
    $this->executeCommand($command, $output);

    if (empty($output)) {
      // Try without the LISTEN filter in case the process is in another state.
      $command = str_replace(" | grep 'LISTEN'", '', $command);
      $this->debug(sprintf('No LISTEN processes found, retrying with command: %s', $command));
      $this->executeCommand($command, $output);
    }

    if (empty($output)) {
      $this->debug('No processes found on port ' . $port);
      return 0;
    }

    // Log all found processes.
    foreach ($output as $i => $line) {
      $this->debug(sprintf('Found process %d: %s', $i + 1, $line));
    }

    foreach ($output as $line) {
      $line = trim((string) preg_replace('/\s+/', ' ', $line));

      $this->debug(sprintf('Processing line: %s', $line));
      $parts = explode(' ', $line);

      // Accept any executable that *starts with* "php" (php, php-fpm, php8.3…).
      if (count($parts) > 1 && str_starts_with($parts[0], 'php') && is_numeric($parts[1])) {
        $pid = intval($parts[1]);
        $this->debug(sprintf('Found PHP process with PID %s using lsof.', $pid));
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
    if (!$this->executeCommand('which netstat 2>/dev/null')) {
      return 0;
    }

    // -p is only available on Linux.
    $command = sprintf("netstat -anp 2>/dev/null | grep ':%s' | grep 'LISTEN'", $port);

    $output = [];
    $this->executeCommand($command, $output);

    if (empty($output)) {
      // Try without the LISTEN filter in case the process is in another state.
      $command = str_replace(" | grep 'LISTEN'", '', $command);
      $this->debug(sprintf('No LISTEN processes found, retrying with command: %s', $command));
      $this->executeCommand($command, $output);
    }

    if (empty($output)) {
      $this->debug('No processes found on port ' . $port);
      return 0;
    }

    // Log all found processes.
    foreach ($output as $i => $line) {
      $this->debug(sprintf('Found process %d: %s', $i + 1, $line));
    }

    foreach ($output as $line) {
      $line = trim((string) preg_replace('/\s+/', ' ', $line));
      $this->debug(sprintf('Processing line: %s', $line));
      $parts = explode(' ', $line);

      foreach ($parts as $part) {
        if (str_contains($part, '/php')) {
          $pid_name_parts = explode('/', $part);
          if (count($pid_name_parts) > 1) {
            $found_pid = $pid_name_parts[0];
            $name = $pid_name_parts[1];
            if (is_numeric($found_pid) && strpos($name, 'php') === 0) {
              $pid = intval($found_pid);
              $this->debug(sprintf('Found PHP process with PID %s using netstat.', $pid));
              return $pid;
            }
          }
        }
      }
    }

    return 0;
  }

  /**
   * Execute a shell command and return the exit code.
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
  protected function debug(string $message): void {
    // @codeCoverageIgnoreStart
    if ($this->debug) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
      $caller = $backtrace[1]['function'] ?? 'unknown';
      print sprintf('[%s()] %s', $caller, $message) . PHP_EOL;
    }
    // @codeCoverageIgnoreEnd
  }

}
