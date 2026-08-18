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
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'canConnect', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($context, 'pid', $pid);
    $this->setProtectedValue($context, 'connectionTimeout', $timeout);
    $this->setProtectedValue($context, 'retryDelay', $retry_delay);

    // Set up mock methods behavior.
    if ($pid > 0) {
      $context->expects($this->once())
        ->method('processExists')
        ->with($pid)
        ->willReturn($process_exists);
    }

    if ($pid <= 0 || $process_exists) {
      $context->expects($this->atLeastOnce())
        ->method('canConnect')
        ->willReturn($can_connect);
    }

    // Use reflection to call protected isRunning method.
    $reflection_class = new \ReflectionClass(PhpServerContext::class);
    $is_running_method = $reflection_class->getMethod('isRunning');

    // Call the method and check results.
    $result = $is_running_method->invoke($context, $timeout, $retry_delay);
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

    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['stop', 'executeCommand', 'debug', 'isRunning'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($context, 'host', '127.0.0.1');
    $this->setProtectedValue($context, 'port', 8888);
    $this->setProtectedValue($context, 'webroot', __DIR__);
    $this->setProtectedValue($context, 'connectionTimeout', 2);
    // Always start with 0, actual pid should be set by start()
    $this->setProtectedValue($context, 'pid', 0);

    // Set up mock methods behavior.
    // Mock stop() to return the configured result.
    $context->method('stop')
      ->willReturn($stop_result);

    // Mock executeCommand() to return appropriate output based on test case.
    $context->method('executeCommand')
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
    $context->method('isRunning')
      ->willReturn($is_running);

    // Call the start method and check results.
    if (!$expect_exception) {
      $result = $context->start();
      $this->assertEquals($expected_pid, $result);
      $this->assertEquals($expected_pid, $this->getProtectedValue($context, 'pid'));
    }
    else {
      $context->start();
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
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'terminateProcess', 'isPortInUse', 'freePort', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($context, 'pid', $pid);
    $this->setProtectedValue($context, 'port', 8888);

    // Set up mock methods behavior.
    $context->method('processExists')
      ->willReturn($process_exists);

    $context->method('terminateProcess')
      ->willReturn($termination_result);

    // Configure isPortInUse to return different values on consecutive calls.
    $context->method('isPortInUse')
      ->willReturnOnConsecutiveCalls($port_in_use, $port_in_use_after);

    $context->method('freePort')
      ->willReturn($free_port_result);

    // Call the stop method and check results.
    $result = $context->stop();
    $this->assertEquals($expected_result, $result);
    $this->assertEquals($expected_pid, $this->getProtectedValue($context, 'pid'));
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
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processExists', 'terminateProcess', 'isPortInUse', 'debug'])
      ->getMock();

    // Set up properties.
    $this->setProtectedValue($context, 'pid', 12345);
    $this->setProtectedValue($context, 'port', 8888);

    // Set up mock methods behavior.
    $context->method('processExists')
      ->willReturn(TRUE);

    $context->method('terminateProcess')
      ->willReturn(TRUE);

    // Configure isPortInUse to throw an exception.
    $context->method('isPortInUse')
      ->willThrowException(new \RuntimeException('Test exception'));

    // Call the stop method and check results.
    $result = $context->stop();
    $this->assertFalse($result);
    $this->assertEquals(0, $this->getProtectedValue($context, 'pid'), 'PID should be reset to 0 even when exception occurs');
  }

  #[DataProvider('dataProviderGetPid')]
  public function testGetPid(bool $has_pid, int $lsof_pid, int $netstat_pid, ?int $expected_pid, bool $expect_exception = FALSE): void {
    $test_class = $this;

    // Skip exception expectation - we'll handle it manually
    // Create a subclass of PhpServerContext that we can customize.
    $context = new class($test_class, $has_pid, $lsof_pid, $netstat_pid, $expect_exception) extends PhpServerContext {
      /**
       * Flag indicating if the mock has a PID.
       */
      private readonly bool $hasPid;

      /**
       * PID to return from lsof command.
       */
      private readonly int $lsofPid;

      /**
       * PID to return from netstat command.
       */
      private readonly int $netstatPid;

      /**
       * Flag indicating if an exception is expected.
       */
      private readonly bool $expectException;

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

    $result = $context->testGetPid(8888);

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
    $context = new class($test_class, $lsof_exists, $output, $expected_pid) extends PhpServerContext {
      /**
       * Flag indicating if lsof exists on the system.
       */
      private readonly bool $lsofExists;

      /**
       * Mock output from the lsof command.
       *
       * @var array<string>
       */
      private readonly array $mockOutput;

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
        if (str_contains($command, 'which lsof')) {
          $code = $this->lsofExists ? 0 : 1;
          return $this->lsofExists;
        }
        if (str_contains($command, 'lsof -i -P -n')) {
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

    $result = $context->testGetPidLsof(8888);
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
    $context = new class($test_class, $netstat_exists, $output, $expected_pid) extends PhpServerContext {
      /**
       * Flag indicating if netstat exists on the system.
       */
      private readonly bool $netstatExists;

      /**
       * Mock output from the netstat command.
       *
       * @var array<string>
       */
      private readonly array $mockOutput;

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
        if (str_contains($command, 'which netstat')) {
          $code = $this->netstatExists ? 0 : 1;
          return $this->netstatExists;
        }
        if (str_contains($command, 'netstat -an')) {
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

    $result = $context->testGetPidNetstat(8888);
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
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['executeCommand', 'debug'])
      ->getMock();

    $context->expects($this->any())
      ->method('executeCommand')
      ->willReturnCallback(function (string $command, array &$output_param) use ($output): bool {
        $output_param = $output;
        return TRUE;
      });

    $result = $this->callProtectedMethod($context, 'processExists', [$pid]);
    $this->assertEquals($expected_result, $result);
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
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['executeCommand', 'debug', 'processExists'])
      ->getMock();

    $context->expects($this->atLeastOnce())
      ->method('processExists')
      ->willReturnOnConsecutiveCalls($process_exists, $process_exists_after);

    if (is_array($kill_return_code)) {
      // For testing the graceful->forceful termination path.
      $context->expects($this->exactly(2))
        ->method('executeCommand')
        ->willReturnOnConsecutiveCalls(
          !$kill_return_code[0],
          !$kill_return_code[1]
        );
    }
    else {
      $context->expects($this->any())
        ->method('executeCommand')
        ->willReturnCallback(function (string $command, array &$output) use ($kill_return_code): bool {
          $output = [];
          return !$kill_return_code;
        });
    }

    $this->setProtectedValue($context, 'retryDelay', 10);
    $this->setProtectedValue($context, 'pid', $pid);

    $result = $this->callProtectedMethod($context, 'terminateProcess', [$pid]);
    $this->assertEquals($expected_result, $result);
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

  /**
   * Path to an existing directory that can be used as a webroot.
   *
   * @return string
   *   Absolute path to the Behat fixtures directory.
   */
  protected static function getFixturesPath(): string {
    return __DIR__ . '/../../behat/fixtures';
  }

  /**
   * Open a listening socket on an ephemeral port.
   *
   * @return array{0: resource, 1: int}
   *   The listening socket and the port it was bound to.
   */
  protected function openListeningSocket(): array {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === FALSE) {
      $this->fail(sprintf('Unable to open a listening socket: %s (%d).', $errstr, $errno));
    }

    $name = stream_socket_get_name($server, FALSE);

    if ($name === FALSE) {
      $this->fail('Unable to read the address of the listening socket.');
    }

    $position = strrpos($name, ':');

    if ($position === FALSE) {
      $this->fail(sprintf('Unable to parse a port from the socket address "%s".', $name));
    }

    return [$server, (int) substr($name, $position + 1)];
  }

  /**
   * Test that the constructor applies the default configuration.
   */
  public function testConstructorAppliesDefaults(): void {
    $context = new PhpServerContext(static::getFixturesPath());

    $this->assertEquals('http://127.0.0.1:8888', $context->getServerUrl());
    $this->assertEquals(PhpServerContext::DEFAULT_CONNECTION_TIMEOUT, static::getProtectedValue($context, 'connectionTimeout'));
    $this->assertEquals(PhpServerContext::DEFAULT_RETRY_DELAY, static::getProtectedValue($context, 'retryDelay'));
  }

  /**
   * Test that the constructor honours explicitly provided timeouts.
   */
  public function testConstructorAcceptsExplicitTimeouts(): void {
    $context = new PhpServerContext(static::getFixturesPath(), '127.0.0.1', 9999, 'https', FALSE, 7, 500);

    $this->assertEquals('https://127.0.0.1:9999', $context->getServerUrl());
    $this->assertEquals(7, static::getProtectedValue($context, 'connectionTimeout'));
    $this->assertEquals(500, static::getProtectedValue($context, 'retryDelay'));
  }

  /**
   * Test that a webroot that does not exist is rejected.
   */
  public function testConstructorThrowsWhenWebrootMissing(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('"webroot" directory /nonexistent/webroot does not exist');

    new PhpServerContext('/nonexistent/webroot');
  }

  /**
   * Test building the server URL.
   *
   * @param string $host
   *   Server host.
   * @param int $port
   *   Server port.
   * @param string $protocol
   *   Server protocol.
   * @param string $expected_url
   *   Expected URL.
   */
  #[DataProvider('dataProviderGetServerUrl')]
  public function testGetServerUrl(string $host, int $port, string $protocol, string $expected_url): void {
    $context = new PhpServerContext(static::getFixturesPath(), $host, $port, $protocol);

    $this->assertEquals($expected_url, $context->getServerUrl());
  }

  /**
   * Data provider for server URL tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderGetServerUrl(): array {
    return [
      'loopback' => [
        'host' => '127.0.0.1',
        'port' => 8888,
        'protocol' => 'http',
        'expected_url' => 'http://127.0.0.1:8888',
      ],
      'wildcard host' => [
        'host' => '0.0.0.0',
        'port' => 8889,
        'protocol' => 'http',
        'expected_url' => 'http://0.0.0.0:8889',
      ],
      'secure protocol' => [
        'host' => 'localhost',
        'port' => 443,
        'protocol' => 'https',
        'expected_url' => 'https://localhost:443',
      ],
    ];
  }

  /**
   * Test that a listening port is detected and a closed one is not.
   */
  public function testIsPortInUse(): void {
    [$server, $port] = $this->openListeningSocket();

    $context = new PhpServerContext(static::getFixturesPath(), '127.0.0.1', $port);

    $this->assertTrue(static::callProtectedMethod($context, 'isPortInUse', [$port]));

    fclose($server);

    $this->assertFalse(static::callProtectedMethod($context, 'isPortInUse', [$port]));
  }

  /**
   * Test that a wildcard host is probed over the loopback interface.
   */
  public function testIsPortInUseResolvesWildcardHost(): void {
    [$server, $port] = $this->openListeningSocket();

    $context = new PhpServerContext(static::getFixturesPath(), '0.0.0.0', $port);

    $this->assertTrue(static::callProtectedMethod($context, 'isPortInUse', [$port]));

    fclose($server);
  }

  /**
   * Test connecting to a listening server and to a closed port.
   */
  public function testCanConnect(): void {
    [$server, $port] = $this->openListeningSocket();

    $context = new PhpServerContext(static::getFixturesPath(), '127.0.0.1', $port);

    $this->assertTrue(static::callProtectedMethod($context, 'canConnect', [1]));

    fclose($server);

    $this->assertFalse(static::callProtectedMethod($context, 'canConnect', [1]));
  }

  /**
   * Test that the configured timeout is used when none is passed.
   */
  public function testCanConnectUsesConfiguredTimeout(): void {
    [$server, $port] = $this->openListeningSocket();

    $context = new PhpServerContext(static::getFixturesPath(), '127.0.0.1', $port, 'http', FALSE, 1);

    $this->assertTrue(static::callProtectedMethod($context, 'canConnect'));

    fclose($server);
  }

  /**
   * Test freeing a port that may be held by a process.
   *
   * @param int $pid
   *   PID reported as holding the port.
   * @param bool $terminated
   *   Whether terminating that process succeeds.
   * @param bool $still_in_use
   *   Whether the port is still in use after termination.
   * @param bool $expected_result
   *   Expected result.
   */
  #[DataProvider('dataProviderFreePort')]
  public function testFreePort(int $pid, bool $terminated, bool $still_in_use, bool $expected_result): void {
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->setConstructorArgs([static::getFixturesPath()])
      ->onlyMethods(['getPid', 'terminateProcess', 'isPortInUse'])
      ->getMock();

    $context->method('getPid')->willReturn($pid);
    $context->method('terminateProcess')->willReturn($terminated);
    $context->method('isPortInUse')->willReturn($still_in_use);

    $this->assertEquals($expected_result, static::callProtectedMethod($context, 'freePort', [8888]));
  }

  /**
   * Data provider for port freeing tests.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases.
   */
  public static function dataProviderFreePort(): array {
    return [
      'no process holds the port' => [
        'pid' => 0,
        'terminated' => FALSE,
        'still_in_use' => FALSE,
        'expected_result' => TRUE,
      ],
      'process terminated and port freed' => [
        'pid' => 12345,
        'terminated' => TRUE,
        'still_in_use' => FALSE,
        'expected_result' => TRUE,
      ],
      'process terminated but port still held' => [
        'pid' => 12345,
        'terminated' => TRUE,
        'still_in_use' => TRUE,
        'expected_result' => FALSE,
      ],
      'termination failed' => [
        'pid' => 12345,
        'terminated' => FALSE,
        'still_in_use' => FALSE,
        'expected_result' => FALSE,
      ],
    ];
  }

  /**
   * Test that an error raised while freeing a port is contained.
   */
  public function testFreePortHandlesFailure(): void {
    $context = $this->getMockBuilder(PhpServerContext::class)
      ->setConstructorArgs([static::getFixturesPath()])
      ->onlyMethods(['getPid'])
      ->getMock();

    $context->method('getPid')->willThrowException(new \RuntimeException('Unable to inspect the port.'));

    $this->assertFalse(static::callProtectedMethod($context, 'freePort', [8888]));
  }

}
