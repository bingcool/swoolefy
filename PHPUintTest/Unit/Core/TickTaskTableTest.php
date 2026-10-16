<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Core\Timer\Tick;
use Swoolefy\Core\Timer\TickManager;

/**
 * 定时器内存表按进程分行，只保存可序列化的任务信息。
 */
final class TickTaskTableTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedConf = [];

    /** @var array<int|string, mixed> */
    private array $savedTickTasks = [];

    /** @var array<int|string, mixed> */
    private array $savedAfterTasks = [];

    private bool $savedWriteFailed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $this->savedConf = $config->getValue();
        $conf = $this->savedConf;
        $conf['enable_table_tick_task'] = true;
        $config->setValue(null, $conf);

        $ticks = new ReflectionProperty(Tick::class, '_tick_tasks');
        $afters = new ReflectionProperty(Tick::class, '_after_tasks');
        $failed = new ReflectionProperty(Tick::class, 'taskTableWriteFailed');
        $this->savedTickTasks = $ticks->getValue();
        $this->savedAfterTasks = $afters->getValue();
        $this->savedWriteFailed = (bool) $failed->getValue();
        $ticks->setValue(null, []);
        $afters->setValue(null, []);
        $failed->setValue(null, false);
    }

    protected function tearDown(): void
    {
        unset(BaseServer::$tableMemory['table_ticker'], BaseServer::$tableMemory['table_after']);
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $config->setValue(null, $this->savedConf);
        $ticks = new ReflectionProperty(Tick::class, '_tick_tasks');
        $afters = new ReflectionProperty(Tick::class, '_after_tasks');
        $failed = new ReflectionProperty(Tick::class, 'taskTableWriteFailed');
        $ticks->setValue(null, $this->savedTickTasks);
        $afters->setValue(null, $this->savedAfterTasks);
        $failed->setValue(null, $this->savedWriteFailed);
        parent::tearDown();
    }

    public function testTimerTableRowsFollowWorkerNum(): void
    {
        $server = new class extends BaseServer {
            public static array $setting = [
                'worker_num' => 100,
                'task_worker_num' => 10,
            ];

            public function __construct()
            {
            }

            public static function rows(): int
            {
                return static::timerTaskTableRows();
            }
        };

        $this->assertSame(142, $server::rows());
    }

    public function testSnapshotOmitsClosureAndKeepsEachProcessRow(): void
    {
        $this->createTaskTable('table_ticker', 'tick_tasks', 8096);

        $ticks = new ReflectionProperty(Tick::class, '_tick_tasks');
        $ticks->setValue(null, [
            7 => [
                'callback' => static function (): void {
                },
                'params' => ['order' => 1, 'skip' => static function (): void {
                }],
                'time_interval' => 1000,
                'timer_id' => 7,
                'start_time' => '2026-10-16 00:00:00',
            ],
            8 => [
                'callback' => [Tick::class, 'tickTimer'],
                'params' => ['nested' => ['ok' => true]],
                'time_interval' => 2000,
                'timer_id' => 8,
                'start_time' => '2026-10-16 00:00:01',
            ],
        ]);

        $parent = $this->aliveForeignPid();
        TableManager::set('table_ticker', (string) $parent, [
            'tick_tasks' => json_encode([
                'pid' => $parent,
                'worker_id' => 3,
                'truncated' => false,
                'tasks' => [
                    '3' => [
                        'timer_id' => 3,
                        'callback' => 'Closure',
                        'time_interval' => 1,
                        'start_time' => '2026-10-16 00:00:02',
                        'pid' => $parent,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $dead = $this->deadPid();
        TableManager::set('table_ticker', (string) $dead, [
            'tick_tasks' => json_encode([
                'pid' => $dead,
                'worker_id' => 1,
                'truncated' => false,
                'tasks' => [
                    '9' => ['timer_id' => 9, 'callback' => 'Closure'],
                ],
            ]),
        ]);

        $this->publish('publishTickTasks');

        $pid = getmypid();
        $rows = TickManager::getTickTasks();
        $this->assertSame('Closure', $rows[$pid . ':7']['callback']);
        $this->assertArrayNotHasKey('params', $rows[$pid . ':7']);
        $this->assertSame(Tick::class . '::tickTimer', $rows[$pid . ':8']['callback']);
        $this->assertSame(['nested' => ['ok' => true]], $rows[$pid . ':8']['params']);
        $this->assertSame(3, $rows[$parent . ':3']['timer_id']);
        $this->assertArrayNotHasKey($dead . ':9', $rows);
        $this->assertFalse(TableManager::exist('table_ticker', (string) $dead));
    }

    public function testOversizedSnapshotTruncatesInsideTheColumn(): void
    {
        $this->createTaskTable('table_ticker', 'tick_tasks', 8096);
        $tasks = [];
        for ($i = 1; $i <= 200; $i++) {
            $tasks[$i] = [
                'callback' => static function (): void {
                },
                'params' => ['blob' => str_repeat('x', 80)],
                'time_interval' => 1000,
                'timer_id' => $i,
                'start_time' => '2026-10-16 00:00:00',
            ];
        }
        $ticks = new ReflectionProperty(Tick::class, '_tick_tasks');
        $ticks->setValue(null, $tasks);

        $this->publish('publishTickTasks');

        $stored = TableManager::get('table_ticker', (string) getmypid(), 'tick_tasks');
        $this->assertIsString($stored);
        $this->assertLessThanOrEqual(8000, strlen($stored));
        $payload = json_decode($stored, true);
        $this->assertTrue($payload['truncated']);
        $this->assertLessThan(200, count($payload['tasks']));
        $this->assertNotEmpty($payload['tasks']);

        $rows = TickManager::getTickTasks();
        $this->assertCount(count($payload['tasks']), $rows);
        $first = $rows[getmypid() . ':1'];
        $this->assertTrue($first['snapshot_truncated']);
    }

    public function testSetFailureIsReportedOnceAndDoesNotThrow(): void
    {
        $this->createTaskTable('table_ticker', 'tick_tasks', 32);
        $ticks = new ReflectionProperty(Tick::class, '_tick_tasks');
        $ticks->setValue(null, [
            1 => [
                'callback' => static function (): void {
                },
                'params' => [],
                'time_interval' => 1000,
                'timer_id' => 1,
                'start_time' => '2026-10-16 00:00:00',
            ],
        ]);

        $log = dirname(__DIR__, 3) . '/Storage/tick-table-test.log';
        if (!is_dir(dirname($log))) {
            mkdir(dirname($log), 0777, true);
        }
        @unlink($log);
        $previous = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $this->publish('publishTickTasks');
            $this->publish('publishTickTasks');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $text = is_file($log) ? (string) file_get_contents($log) : '';
        @unlink($log);
        $this->assertSame(1, substr_count($text, 'timer table table_ticker set failed'));
        $this->assertFalse(TableManager::exist('table_ticker', (string) getmypid()));
    }

    private function publish(string $method): void
    {
        $publish = new ReflectionMethod(Tick::class, $method);
        $publish->invoke(null);
    }

    private function createTaskTable(string $name, string $field, int $width): void
    {
        unset(BaseServer::$tableMemory[$name]);
        TableManager::createTable([
            $name => [
                'size' => 64,
                'fields' => [
                    [$field, 'string', $width],
                ],
            ],
        ]);
    }

    private function aliveForeignPid(): int
    {
        $parent = function_exists('posix_getppid') ? posix_getppid() : 1;
        if ($parent > 0 && $parent !== getmypid() && $this->pidIsAlive($parent)) {
            return $parent;
        }

        return 1;
    }

    private function deadPid(): int
    {
        for ($pid = 200000; $pid < 200200; $pid++) {
            if (!$this->pidIsAlive($pid)) {
                return $pid;
            }
        }

        $this->fail('没有找到已退出的 pid');
    }

    private function pidIsAlive(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            return $pid === 1 || $pid === getmypid();
        }
        if (posix_kill($pid, 0)) {
            return true;
        }

        return function_exists('posix_errno') && posix_errno() !== 3;
    }
}
