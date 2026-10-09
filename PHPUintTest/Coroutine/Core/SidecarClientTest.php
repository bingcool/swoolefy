<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine\Socket;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\Sidecar\Dto\SidecarResponseDto;
use Swoolefy\Core\Sidecar\FrameCodec;
use Swoolefy\Core\Sidecar\OutcomeUnknownException;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\SidecarRegistry;
use Swoolefy\Core\Sidecar\SidecarSpec;
use Swoolefy\Support\Rocketmq\Dto\HealthCheckRequestDto;

final class SidecarClientTest extends CoroutineTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/sidecar-cli-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        SidecarRegistry::reset();
    }

    protected function tearDown(): void
    {
        SidecarRegistry::reset();
        if (is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink($this->dir . '/' . $item);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testCallOutsideCoroutineFailsBeforeConnect(): void
    {
        $path = $this->dir . '/none.sock';
        SidecarRegistry::add($this->spec('probe', $path));
        $this->expectException(SidecarException::class);
        (new SidecarClient())->call('probe', 'health.check', new HealthCheckRequestDto(), 200);
    }

    public function testTwoCoroutinesReceiveTheirOwnResponse(): void
    {
        $this->runInCoroutine(function (): void {
            $path = $this->dir . '/echo.sock';
            $this->serve($path, function (Socket $conn): void {
                $raw = $this->readFrame($conn);
                $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $resp = json_encode([
                    'requestId' => $req['requestId'],
                    'code' => 'OK',
                    'message' => '',
                    'payload' => ['echo' => $req['payload']['echo'] ?? ''],
                ], FrameCodec::JSON_FLAGS);
                $this->writeAll($conn, FrameCodec::encode((string) $resp, 65536));
            });
            SidecarRegistry::add($this->spec('rocketmq', $path));
            $left = null;
            $right = null;
            $errors = [];
            go(function () use (&$left, &$errors): void {
                try {
                    $left = (new SidecarClient())->call('rocketmq', 'health.check', new EchoDto('left'), 1000);
                } catch (\Throwable $e) {
                    $errors[] = $e;
                }
            });
            go(function () use (&$right, &$errors): void {
                try {
                    $right = (new SidecarClient())->call('rocketmq', 'health.check', new EchoDto('right'), 1000);
                } catch (\Throwable $e) {
                    $errors[] = $e;
                }
            });
            \Swoole\Coroutine::sleep(0.5);
            $this->assertSame([], array_map(static fn (\Throwable $e): string => $e->getMessage(), $errors));
            $this->assertInstanceOf(SidecarResponseDto::class, $left);
            $this->assertInstanceOf(SidecarResponseDto::class, $right);
            $this->assertSame('left', $left->getPayload()['echo']);
            $this->assertSame('right', $right->getPayload()['echo']);
        });
    }

    public function testConnectFailureIsRetryable(): void
    {
        $this->runInCoroutine(function (): void {
            SidecarRegistry::add($this->spec('rocketmq', $this->dir . '/missing.sock'));
            try {
                (new SidecarClient())->call('rocketmq', 'health.check', new HealthCheckRequestDto(), 200);
                $this->fail('expected connect failure');
            } catch (OutcomeUnknownException $e) {
                $this->fail('connect failure must not be outcome unknown: ' . $e->getMessage());
            } catch (SidecarException $e) {
                $this->assertStringContainsString('connect failed', $e->getMessage());
            }
        });
    }

    public function testTimeoutAfterWriteDoesNotRetry(): void
    {
        $this->runInCoroutine(function (): void {
            $path = $this->dir . '/hang.sock';
            $reads = 0;
            $this->serve($path, function (Socket $conn) use (&$reads): void {
                $reads++;
                $this->readFrame($conn);
                \Swoole\Coroutine::sleep(1);
            });
            SidecarRegistry::add($this->spec('rocketmq', $path));
            $attempts = 0;
            try {
                $attempts++;
                (new SidecarClient())->call('rocketmq', 'health.check', new HealthCheckRequestDto(), 200);
                $this->fail('expected timeout');
            } catch (OutcomeUnknownException) {
                $this->assertSame(1, $attempts);
                $this->assertSame(1, $reads);
            }
        });
    }

    public function testRequestIdMismatchIsOutcomeUnknown(): void
    {
        $this->runInCoroutine(function (): void {
            $path = $this->dir . '/mismatch.sock';
            $this->serve($path, function (Socket $conn): void {
                $this->readFrame($conn);
                $resp = json_encode([
                    'requestId' => 'other',
                    'code' => 'OK',
                    'message' => '',
                    'payload' => [],
                ], FrameCodec::JSON_FLAGS);
                $this->writeAll($conn, FrameCodec::encode((string) $resp, 65536));
            });
            SidecarRegistry::add($this->spec('rocketmq', $path));
            $this->expectException(OutcomeUnknownException::class);
            (new SidecarClient())->call('rocketmq', 'health.check', new HealthCheckRequestDto(), 1000);
        });
    }

    public function testNestedDtoIsRejectedBeforeConnect(): void
    {
        $this->runInCoroutine(function (): void {
            $path = $this->dir . '/nested.sock';
            $accepts = 0;
            $this->serve($path, function (Socket $conn) use (&$accepts): void {
                $accepts++;
                $conn->close();
            });
            SidecarRegistry::add($this->spec('rocketmq', $path));
            $inner = new EchoDto('x');
            $outer = new class($inner) extends AbstractDto {
                public function __construct(protected AbstractDto $child)
                {
                }
            };
            try {
                (new SidecarClient())->call('rocketmq', 'health.check', $outer, 200);
                $this->fail('expected nested dto rejection');
            } catch (SidecarException $e) {
                $this->assertStringContainsString('toArray()', $e->getMessage());
                $this->assertSame(0, $accepts);
            }
        });
    }

    private function spec(string $name, string $path): SidecarSpec
    {
        return new SidecarSpec(
            name: $name,
            binary: '/bin/true',
            args: [],
            socketPath: $path,
            maxFrameBytes: 65536,
            defaultTimeoutMs: 300,
            maxTimeoutMs: 1000,
            maxInflight: 8,
            shutdownGraceMs: 100,
            plugins: 'demo',
            environment: [],
        );
    }

    private function serve(string $path, callable $handler): void
    {
        @unlink($path);
        $server = new Socket(AF_UNIX, SOCK_STREAM, 0);
        $this->assertTrue($server->bind($path));
        $this->assertTrue($server->listen());
        go(function () use ($server, $handler): void {
            while (true) {
                $conn = $server->accept(1.5);
                if (!$conn instanceof Socket) {
                    return;
                }
                go(function () use ($conn, $handler): void {
                    $handler($conn);
                    $conn->close();
                });
            }
        });
    }

    private function writeAll(Socket $conn, string $bytes): void
    {
        $offset = 0;
        $total = strlen($bytes);
        while ($offset < $total) {
            $n = $conn->send(substr($bytes, $offset));
            $this->assertIsInt($n);
            $this->assertGreaterThan(0, $n);
            $offset += $n;
        }
    }

    private function readFrame(Socket $conn): string
    {
        $header = '';
        while (strlen($header) < 4) {
            $chunk = $conn->recv(4 - strlen($header));
            $this->assertIsString($chunk);
            $header .= $chunk;
        }
        $unpacked = unpack('Nlen', $header);
        $length = (int) ($unpacked['len'] ?? 0);
        $body = '';
        while (strlen($body) < $length) {
            $chunk = $conn->recv($length - strlen($body));
            $this->assertIsString($chunk);
            $body .= $chunk;
        }

        return $body;
    }
}

final class EchoDto extends AbstractDto
{
    public function __construct(protected string $echo = '')
    {
    }
}
