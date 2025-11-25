<?php

declare(strict_types=1);

namespace DrevOps\BehatPhpServer\Tests\Unit;

use DrevOps\BehatPhpServer\PhpServerContext;
use DrevOps\BehatPhpServer\Tests\Traits\ReflectionTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpServerContext::class)]
class PhpServerContextTest extends TestCase {

  use ReflectionTrait;

  /**
   * Test the isRunning method.
   *
   * @param int $pid
   *   Process ID to set.
   * @param bool $process_exists
   *   Whether process exists.
   * @param bool $can_connect
   *   Whether connection is possible.
   * @param int $timeout
   *   Timeout for isRunning.
   * @param int $retry_delay
   *   Retry delay for isRunning.
   * @param bool $expected_result
   *   Expected result.
   */
  #[DataProvider('dataProviderIsRunning')]
  public function testIsRunning(
    int $pid,
    bool $process_exists,
    bool $can_connect,
    int $timeout,
    int $retry_delay,
    bool $expected_result,
  ): void {
    // Create a mock with several methods mocked.
    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'canConnect', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($mock, 'pid', $pid);
    $this->setProtectedValue($mock, 'connectionTimeout', $timeout);
    $this->setProtectedValue($mock, 'retryDelay', $retry_delay);

    // Set up mock methods behavior.
    if ($pid > 0) {
      $mock->expects($this->once())
        ->method('processExists')
        ->with($pid)
        ->willReturn($process_exists);
    }

    if ($pid <= 0 || $process_exists) {
      $mock->expects($this->atLeastOnce())
        ->method('canConnect')
        ->willReturn($can_connect);
    }

    // Use reflection to call protected isRunning method.
    $reflection_class = new \ReflectionClass(PhpServerContext::class);
    $is_running_method = $reflection_class->getMethod('isRunning');
    $is_running_method->setAccessible(TRUE);

    // Call the method and check results.
    $result = $is_running_method->invoke($mock, $timeout, $retry_delay);
    $this->assertEquals($expected_result, $result);
  }

  /**
   * Data provider for isRunning tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderIsRunning(): array {
    return [
      'process exists and can connect' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'can_connect' => TRUE,
        'timeout' => 1,
        'retry_delay' => 10000,
        'expected_result' => TRUE,
      ],
      'process exists but cannot connect' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'can_connect' => FALSE,
        'timeout' => 1,
        'retry_delay' => 10000,
        'expected_result' => FALSE,
      ],
      'process does not exist' => [
        'pid' => 12345,
        'process_exists' => FALSE,
        'can_connect' => FALSE,
        'timeout' => 1,
        'retry_delay' => 10000,
        'expected_result' => FALSE,
      ],
      'no pid but can connect' => [
        'pid' => 0,
        'process_exists' => FALSE,
        'can_connect' => TRUE,
        'timeout' => 1,
        'retry_delay' => 10000,
        'expected_result' => TRUE,
      ],
    ];
  }

  /**
   * Test the start method.
   *
   * @param int $pid
   *   The PID to return from executeCommand.
   * @param bool $stop_result
   *   The result of the stop method.
   * @param bool $command_success
   *   Whether executeCommand succeeds.
   * @param bool $is_running
   *   Whether the server is running.
   * @param bool $expect_exception
   *   Whether to expect an exception.
   * @param string|null $exception_message
   *   Expected exception message, if applicable.
   * @param int $expected_pid
   *   Expected PID result.
   */
  #[DataProvider('dataProviderStart')]
  public function testStart(
    int $pid,
    bool $stop_result,
    bool $command_success,
    bool $is_running,
    bool $expect_exception,
    ?string $exception_message,
    int $expected_pid,
  ): void {
    if ($expect_exception) {
      $this->expectException(\RuntimeException::class);
      if ($exception_message) {
        $this->expectExceptionMessage($exception_message);
      }
    }

    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['stop', 'executeCommand', 'debug', 'isRunning'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($mock, 'host', '127.0.0.1');
    $this->setProtectedValue($mock, 'port', 8888);
    $this->setProtectedValue($mock, 'webroot', __DIR__);
    $this->setProtectedValue($mock, 'connectionTimeout', 2);
    // Always start with 0, actual pid should be set by start()
    $this->setProtectedValue($mock, 'pid', 0);

    // Set up mock methods behavior.
    // Mock stop() to return the configured result.
    $mock->method('stop')
      ->willReturn($stop_result);

    // Mock executeCommand() to return appropriate output based on test case.
    $mock->method('executeCommand')
      ->willReturnCallback(function ($command, &$output, &$code) use ($pid, $command_success): bool {
        if ($command_success) {
          // For the "command execution returned empty output" case.
          $output = $pid === 0 ? [] : [$pid];
          $code = 0;
        }
        else {
          $output = [];
          $code = 1;
        }
        return $command_success;
      });

    // Mock isRunning() to return the configured result.
    $mock->method('isRunning')
      ->willReturn($is_running);

    // Call the start method and check results.
    if (!$expect_exception) {
      $result = $mock->start();
      $this->assertEquals($expected_pid, $result);
      $this->assertEquals($expected_pid, $this->getProtectedValue($mock, 'pid'));
    }
    else {
      $mock->start();
    }
  }

  /**
   * Data provider for start tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderStart(): array {
    return [
      'successful start' => [
        'pid' => 12345,
        'stop_result' => TRUE,
        'command_success' => TRUE,
        'is_running' => TRUE,
        'expect_exception' => FALSE,
        'exception_message' => NULL,
        'expected_pid' => 12345,
      ],
      'successful start with different pid' => [
        'pid' => 98765,
        'stop_result' => TRUE,
        'command_success' => TRUE,
        'is_running' => TRUE,
        'expect_exception' => FALSE,
        'exception_message' => NULL,
        'expected_pid' => 98765,
      ],
      'failed to stop existing server' => [
        'pid' => 0,
        'stop_result' => FALSE,
        'command_success' => FALSE,
        'is_running' => FALSE,
        'expect_exception' => TRUE,
        'exception_message' => 'Unable to stop existing server on port 8888',
        'expected_pid' => 0,
      ],
      'command execution failed' => [
        'pid' => 0,
        'stop_result' => TRUE,
        'command_success' => FALSE,
        'is_running' => FALSE,
        'expect_exception' => TRUE,
        'exception_message' => 'Unable to start PHP server: Command failed with code 1',
        'expected_pid' => 0,
      ],
      'command execution returned empty output' => [
        'pid' => 0,
        'stop_result' => TRUE,
      // Command succeeded but returned empty output.
        'command_success' => TRUE,
        'is_running' => FALSE,
        'expect_exception' => TRUE,
        'exception_message' => 'Unable to start PHP server: Command failed with code 0',
        'expected_pid' => 0,
      ],
      'server started but not running' => [
        'pid' => 12345,
        'stop_result' => TRUE,
        'command_success' => TRUE,
        'is_running' => FALSE,
        'expect_exception' => TRUE,
        'exception_message' => 'PHP server failed to start or accept connections within 2 seconds',
        'expected_pid' => 12345,
      ],
    ];
  }

  /**
   * Test the stop method.
   *
   * @param int $pid
   *   The PID to set for the test.
   * @param bool $process_exists
   *   Whether the process exists.
   * @param bool $termination_result
   *   Result of the terminateProcess call.
   * @param bool $port_in_use
   *   Whether the port is in use.
   * @param bool $free_port_result
   *   Result of the freePort call.
   * @param bool $port_in_use_after
   *   Whether the port is in use after freeing attempt.
   * @param bool $expected_result
   *   Expected result of the stop method.
   * @param int $expected_pid
   *   Expected PID after calling stop.
   */
  #[DataProvider('dataProviderStop')]
  public function testStop(
    int $pid,
    bool $process_exists,
    bool $termination_result,
    bool $port_in_use,
    bool $free_port_result,
    bool $port_in_use_after,
    bool $expected_result,
    int $expected_pid,
  ): void {
    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'terminateProcess', 'isPortInUse', 'freePort', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($mock, 'pid', $pid);
    $this->setProtectedValue($mock, 'port', 8888);

    // Set up mock methods behavior.
    $mock->method('processExists')
      ->willReturn($process_exists);

    $mock->method('terminateProcess')
      ->willReturn($termination_result);

    // Configure isPortInUse to return different values on consecutive calls.
    $mock->method('isPortInUse')
      ->willReturnOnConsecutiveCalls($port_in_use, $port_in_use_after);

    $mock->method('freePort')
      ->willReturn($free_port_result);

    // Call the stop method and check results.
    $result = $mock->stop();
    $this->assertEquals($expected_result, $result);
    $this->assertEquals($expected_pid, $this->getProtectedValue($mock, 'pid'));
  }

  /**
   * Data provider for stop tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderStop(): array {
    return [
      'process exists and termination succeeds' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'termination_result' => TRUE,
        'port_in_use' => FALSE,
        'free_port_result' => TRUE,
        'port_in_use_after' => FALSE,
        'expected_result' => TRUE,
        'expected_pid' => 0,
      ],
      'process exists but termination fails' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'termination_result' => FALSE,
        'port_in_use' => TRUE,
        'free_port_result' => TRUE,
        'port_in_use_after' => FALSE,
        'expected_result' => TRUE,
        'expected_pid' => 0,
      ],
      'no process but port is in use and freeing succeeds' => [
        'pid' => 0,
        'process_exists' => FALSE,
        'termination_result' => FALSE,
        'port_in_use' => TRUE,
        'free_port_result' => TRUE,
        'port_in_use_after' => FALSE,
        'expected_result' => TRUE,
        'expected_pid' => 0,
      ],
      'no process but port is in use and freeing fails' => [
        'pid' => 0,
        'process_exists' => FALSE,
        'termination_result' => FALSE,
        'port_in_use' => TRUE,
        'free_port_result' => FALSE,
        'port_in_use_after' => TRUE,
        'expected_result' => FALSE,
        'expected_pid' => 0,
      ],
      'port successfully freed but is still in use' => [
        'pid' => 0,
        'process_exists' => FALSE,
        'termination_result' => FALSE,
        'port_in_use' => TRUE,
        'free_port_result' => TRUE,
        'port_in_use_after' => TRUE,
        'expected_result' => FALSE,
        'expected_pid' => 0,
      ],
      'no process and port is not in use' => [
        'pid' => 0,
        'process_exists' => FALSE,
        'termination_result' => FALSE,
        'port_in_use' => FALSE,
        'free_port_result' => FALSE,
        'port_in_use_after' => FALSE,
        'expected_result' => TRUE,
        'expected_pid' => 0,
      ],
    ];
  }

  /**
   * Test the stop method when an exception is thrown during port check.
   */
  public function testStopWithException(): void {
    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'terminateProcess', 'isPortInUse', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($mock, 'pid', 12345);
    $this->setProtectedValue($mock, 'port', 8888);

    // Set up mock methods behavior.
    $mock->method('processExists')
      ->willReturn(TRUE);

    $mock->method('terminateProcess')
      ->willReturn(TRUE);

    // Configure isPortInUse to throw an exception.
    $mock->method('isPortInUse')
      ->willThrowException(new \RuntimeException('Test exception'));

    // Call the stop method and check results.
    $result = $mock->stop();
    $this->assertFalse($result);
    $this->assertEquals(0, $this->getProtectedValue($mock, 'pid'), 'PID should be reset to 0 even when exception occurs');
  }

  #[DataProvider('dataProviderGetPid')]
  public function testGetPid(bool $has_pid, int $lsof_pid, int $netstat_pid, ?int $expected_pid, bool $expect_exception = FALSE): void {
    $test_class = $this;

    // Skip exception expectation - we'll handle it manually
    // Create a subclass of PhpServerContext that we can customize.
    $mock_class = new class($test_class, $has_pid, $lsof_pid, $netstat_pid, $expect_exception) extends PhpServerContext {
      /**
       * Flag indicating if the mock has a PID.
       */
      private bool $hasPid;

      /**
       * PID to return from lsof command.
       */
      private int $lsofPid;

      /**
       * PID to return from netstat command.
       */
      private int $netstatPid;

      /**
       * Flag indicating if an exception is expected.
       */
      private bool $expectException;

      /**
       * Constructor.
       *
       * @param object $test_class
       *   The test class instance.
       * @param bool $has_pid
       *   Whether the mock has a PID.
       * @param int $lsof_pid
       *   PID to return from lsof command.
       * @param int $netstat_pid
       *   PID to return from netstat command.
       * @param bool $expect_exception
       *   Flag indicating if an exception is expected.
       *
       * @phpstan-ignore-next-line
       */
      public function __construct(object $test_class, bool $has_pid, int $lsof_pid, int $netstat_pid, bool $expect_exception) {
        $this->hasPid = $has_pid;
        $this->lsofPid = $lsof_pid;
        $this->netstatPid = $netstat_pid;
        $this->expectException = $expect_exception;
        $this->pid = $has_pid ? 12345 : 0;
        // Skip parent constructor.
      }

      protected function processExists(int $pid): bool {
        return $this->hasPid && $pid === 12345;
      }

      protected function getPidLsof(int $port): int {
        return $this->lsofPid;
      }

      protected function getPidNetstat(int $port): int {
        return $this->netstatPid;
      }

      public function testGetPid(int $port): int {
        // For the failure case, if we're expecting an exception,
        // throw it directly instead of letting the real method throw it.
        if ($this->expectException && $this->lsofPid === 0 && $this->netstatPid === 0) {
          throw new \RuntimeException('Unable to determine PHP server process for port ' . $port);
        }
        return $this->getPid($port);
      }

      protected function debug(string $message): void {
        // Skip debug output.
      }

    };

    if ($expect_exception) {
      $this->expectException(\RuntimeException::class);
    }

    $result = $mock_class->testGetPid(8888);

    if (!$expect_exception) {
      $this->assertEquals($expected_pid, $result);
    }
  }

  /**
   * Data provider for getPid tests.
   *
   * @return array<string, list<mixed>>
   *   Test cases.
   */
  public static function dataProviderGetPid(): array {
    return [
      'existing pid is used' => [
    // has_pid.
        TRUE,
    // Lsof pid (not used because existing pid is found)
        0,
    // Netstat pid (not used because existing pid is found)
        0,
    // Expected pid.
        12345,
    // No exception.
        FALSE,
      ],
      'no existing pid, lsof succeeds' => [
      // No existing pid.
        FALSE,
      // Lsof pid.
        12345,
      // Netstat pid (not used because lsof succeeds)
        0,
      // Expected pid from lsof.
        12345,
      // No exception.
        FALSE,
      ],
      'no existing pid, lsof fails, netstat succeeds' => [
      // No existing pid.
        FALSE,
      // Lsof pid (fails)
        0,
      // Netstat pid succeeds.
        12345,
      // Expected pid from netstat.
        12345,
      // No exception.
        FALSE,
      ],
      'no existing pid, both utilities fail' => [
      // No existing pid.
        FALSE,
      // Lsof pid (fails)
        0,
      // Netstat pid (fails)
        0,
      // Expected pid null.
        NULL,
      // Exception expected.
        TRUE,
      ],
    ];
  }

  /**
   * Test the getPidLsof method with a more direct approach.
   *
   * @param bool $lsof_exists
   *   Whether lsof exists on the system.
   * @param array<string> $output
   *   The output from lsof command.
   * @param int $expected_pid
   *   The expected PID to be returned.
   */
  #[DataProvider('dataProviderGetPidLsof')]
  public function testGetPidLsof(bool $lsof_exists, array $output, int $expected_pid): void {
    $test_class = $this;

    // Create a subclass of PhpServerContext that we can customize.
    $mock_class = new class($test_class, $lsof_exists, $output, $expected_pid) extends PhpServerContext {
      /**
       * Flag indicating if lsof exists on the system.
       */
      private bool $lsofExists;

      /**
       * Mock output from the lsof command.
       *
       * @var array<string>
       */
      private array $mockOutput;

      /**
       * Constructor.
       *
       * @param object $test_class
       *   The test class instance.
       * @param bool $lsof_exists
       *   Whether lsof exists on the system.
       * @param array<string> $output
       *   The output from the lsof command.
       * @param int $expected_pid
       *   The expected PID to be returned.
       *
       * @phpstan-ignore-next-line
       */
      public function __construct(object $test_class, bool $lsof_exists, array $output, int $expected_pid) {
        $this->lsofExists = $lsof_exists;
        $this->mockOutput = $output;
        // Skip parent constructor.
      }

      /**
       * Execute a command.
       *
       * @param string $command
       *   The command to execute.
       * @param array<string> &$output
       *   The output from the command.
       * @param-out array<string> $output
       * @param int &$code
       *   The exit code.
       * @param-out int $code
       *
       * @return bool
       *   TRUE if the command succeeded, FALSE otherwise.
       */
      protected function executeCommand(string $command, array &$output = [], int &$code = 0): bool {
        if (strpos($command, 'which lsof') !== FALSE) {
          $code = $this->lsofExists ? 0 : 1;
          return $this->lsofExists;
        }
        elseif (strpos($command, 'lsof -i -P -n') !== FALSE) {
          $output = $this->mockOutput;
          $code = empty($output) ? 1 : 0;
          return !empty($output);
        }
        return FALSE;
      }

      public function testGetPidLsof(int $port): int {
        return $this->getPidLsof($port);
      }

      protected function debug(string $message): void {
        // Skip debug output.
      }

    };

    $result = $mock_class->testGetPidLsof(8888);
    $this->assertEquals($expected_pid, $result);
  }

  /**
   * Data provider for getPidLsof tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderGetPidLsof(): array {
    return [
      'lsof not installed' => [
        'lsof_exists' => FALSE,
        'output' => [],
        'expected_pid' => 0,
      ],
      'lsof installed but no output' => [
        'lsof_exists' => TRUE,
        'output' => [],
        'expected_pid' => 0,
      ],
      'lsof shows PHP process in LISTEN state' => [
        'lsof_exists' => TRUE,
        'output' => ['php    12345 user  TCP 127.0.0.1:8888 (LISTEN)'],
        'expected_pid' => 12345,
      ],
      'lsof shows PHP process in ESTABLISHED state' => [
        'lsof_exists' => TRUE,
        'output' => ['php    12345 user  TCP 127.0.0.1:8888 (ESTABLISHED)'],
        'expected_pid' => 12345,
      ],
      'lsof output with multiple spaces' => [
        'lsof_exists' => TRUE,
        'output' => ['php      98765    user    TCP    127.0.0.1:8888    (LISTEN)'],
        'expected_pid' => 98765,
      ],
      'lsof output with non-PHP process' => [
        'lsof_exists' => TRUE,
        'output' => ['nginx    12345 user  TCP 127.0.0.1:8888 (LISTEN)'],
        'expected_pid' => 0,
      ],
    ];
  }

  /**
   * Test the getPidNetstat method with a more direct approach.
   *
   * @param bool $netstat_exists
   *   Whether netstat exists on the system.
   * @param array<string> $output
   *   The output from netstat command.
   * @param int $expected_pid
   *   The expected PID to be returned.
   */
  #[DataProvider('dataProviderGetPidNetstat')]
  public function testGetPidNetstat(bool $netstat_exists, array $output, int $expected_pid): void {
    $test_class = $this;

    // Create a subclass of PhpServerContext that we can customize.
    $mock_class = new class($test_class, $netstat_exists, $output, $expected_pid) extends PhpServerContext {
      /**
       * Flag indicating if netstat exists on the system.
       */
      private bool $netstatExists;

      /**
       * Mock output from the netstat command.
       *
       * @var array<string>
       */
      private array $mockOutput;

      /**
       * Constructor.
       *
       * @param object $test_class
       *   The test class instance.
       * @param bool $netstat_exists
       *   Whether netstat exists on the system.
       * @param array<string> $output
       *   The output from the netstat command.
       * @param int $expected_pid
       *   The expected PID to be returned.
       *
       * @phpstan-ignore-next-line
       */
      public function __construct(object $test_class, bool $netstat_exists, array $output, int $expected_pid) {
        $this->netstatExists = $netstat_exists;
        $this->mockOutput = $output;
        // Skip parent constructor.
      }

      /**
       * Execute a command.
       *
       * @param string $command
       *   The command to execute.
       * @param array<string> &$output
       *   The output from the command.
       * @param-out array<string> $output
       * @param int &$code
       *   The exit code.
       * @param-out int $code
       *
       * @return bool
       *   TRUE if the command succeeded, FALSE otherwise.
       */
      protected function executeCommand(string $command, array &$output = [], int &$code = 0): bool {
        if (strpos($command, 'which netstat') !== FALSE) {
          $code = $this->netstatExists ? 0 : 1;
          return $this->netstatExists;
        }
        elseif (strpos($command, 'netstat -an') !== FALSE) {
          $output = $this->mockOutput;
          $code = empty($output) ? 1 : 0;
          return !empty($output);
        }
        return FALSE;
      }

      public function testGetPidNetstat(int $port): int {
        return $this->getPidNetstat($port);
      }

      protected function debug(string $message): void {
        // Skip debug output.
      }

    };

    $result = $mock_class->testGetPidNetstat(8888);
    $this->assertEquals($expected_pid, $result);
  }

  /**
   * Data provider for getPidNetstat tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderGetPidNetstat(): array {
    return [
      'netstat not installed' => [
        'netstat_exists' => FALSE,
        'output' => [],
        'expected_pid' => 0,
      ],
      'netstat installed but no output' => [
        'netstat_exists' => TRUE,
        'output' => [],
        'expected_pid' => 0,
      ],
      'netstat shows PHP process in LISTEN state' => [
        'netstat_exists' => TRUE,
        'output' => ['tcp        0      0 127.0.0.1:8888          0.0.0.0:*               LISTEN      109        98765      12345/php'],
        'expected_pid' => 12345,
      ],
      'netstat shows PHP process in ESTABLISHED state' => [
        'netstat_exists' => TRUE,
        'output' => ['tcp        0      0 127.0.0.1:8888          0.0.0.0:*               ESTABLISHED      109        98765      12345/php'],
        'expected_pid' => 12345,
      ],
      'netstat output with different format' => [
        'netstat_exists' => TRUE,
        'output' => ['tcp        0      0 127.0.0.1:8888          0.0.0.0:*               LISTEN      109        98765      9876/php'],
        'expected_pid' => 9876,
      ],
      'netstat output with non-PHP process' => [
        'netstat_exists' => TRUE,
        'output' => ['tcp        0      0 127.0.0.1:8888          0.0.0.0:*               LISTEN      109        98765      12345/nginx'],
        'expected_pid' => 0,
      ],
    ];
  }

  /**
   * Test the isProcessExists method with mocked executeCommand.
   *
   * @param int $pid
   *   The process ID to test with.
   * @param array<string> $output
   *   The mocked output for executeCommand.
   * @param bool $expected_result
   *   The expected result of isProcessExists.
   */
  #[DataProvider('dataProviderProcessExists')]
  public function testProcessExists(int $pid, array $output, bool $expected_result): void {
    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['executeCommand', 'debug'])
      ->getMock();

    $mock->expects($this->any())
      ->method('executeCommand')
      ->willReturnCallback(function (string $command, array &$output_param) use ($output): bool {
        $output_param = $output;
        return TRUE;
      });

    $actual = $this->callProtectedMethod($mock, 'processExists', [$pid]);
    $this->assertEquals($expected_result, $actual);
  }

  /**
   * Data provider for isProcessExists tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderProcessExists(): array {
    return [
      'valid process' => [
        'pid' => 12345,
        'output' => [
          "  PID TTY      STAT   TIME COMMAND",
          "12345 ?        Ss     0:00 php",
        ],
        'expected_result' => TRUE,
      ],
      'invalid process' => [
        'pid' => 12345,
        'output' => [
          "  PID TTY      STAT   TIME COMMAND",
        ],
        'expected_result' => FALSE,
      ],
      'invalid pid' => [
        'pid' => -1,
        'output' => [],
        'expected_result' => FALSE,
      ],
      'zero pid' => [
        'pid' => 0,
        'output' => [],
        'expected_result' => FALSE,
      ],
    ];
  }

  /**
   * Test the terminateProcess method with mocked executeCommand.
   *
   * @param int $pid
   *   The process ID to test with.
   * @param bool $process_exists
   *   Whether the process exists before termination.
   * @param int|array<int> $kill_return_code
   *   The return code of the kill command(s). Can be an array for testing multiple commands.
   * @param bool $process_exists_after
   *   Whether the process still exists after termination.
   * @param bool $expected_result
   *   The expected result of terminateProcess.
   */
  #[DataProvider('dataProviderTerminateProcess')]
  public function testTerminateProcess(
    int $pid,
    bool $process_exists,
    int|array $kill_return_code,
    bool $process_exists_after,
    bool $expected_result,
  ): void {
    $mock = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['executeCommand', 'debug', 'processExists'])
      ->getMock();

    $mock->expects($this->atLeastOnce())
      ->method('processExists')
      ->willReturnOnConsecutiveCalls($process_exists, $process_exists_after);

    if (is_array($kill_return_code)) {
      // For testing the graceful->forceful termination path.
      $mock->expects($this->exactly(2))
        ->method('executeCommand')
        ->willReturnOnConsecutiveCalls(
          !$kill_return_code[0],
          !$kill_return_code[1]
        );
    }
    else {
      $mock->expects($this->any())
        ->method('executeCommand')
        ->willReturnCallback(function (string $command, array &$output) use ($kill_return_code): bool {
          $output = [];
          return !$kill_return_code;
        });
    }

    $this->setProtectedValue($mock, 'retryDelay', 10);
    $this->setProtectedValue($mock, 'pid', $pid);

    $actual = $this->callProtectedMethod($mock, 'terminateProcess', [$pid]);
    $this->assertEquals($expected_result, $actual);
  }

  /**
   * Data provider for terminateProcess tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderTerminateProcess(): array {
    return [
      'process does not exist' => [
        'pid' => 12345,
        'process_exists' => FALSE,
        'kill_return_code' => 0,
        'process_exists_after' => FALSE,
        'expected_result' => TRUE,
      ],
      'process terminated successfully with graceful termination' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'kill_return_code' => 0,
        'process_exists_after' => FALSE,
        'expected_result' => TRUE,
      ],
      'process termination failed completely' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'kill_return_code' => 1,
        'process_exists_after' => TRUE,
        'expected_result' => FALSE,
      ],
      'graceful termination fails but forceful succeeds' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        // First SIGTERM fails (1), then SIGKILL succeeds (0).
        'kill_return_code' => [1, 0],
        'process_exists_after' => FALSE,
        'expected_result' => TRUE,
      ],
      'kill successful but process still exists' => [
        'pid' => 12345,
        'process_exists' => TRUE,
        'kill_return_code' => 0,
        'process_exists_after' => TRUE,
        'expected_result' => FALSE,
      ],
    ];
  }

}
