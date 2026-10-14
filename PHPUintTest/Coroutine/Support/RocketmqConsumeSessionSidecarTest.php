<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Support;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine\Socket;
use Swoolefy\Core\Sidecar\FrameCodec;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\SidecarRegistry;
use Swoolefy\Core\Sidecar\SidecarSpec;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;
use Swoolefy\Support\Rocketmq\RocketmqConsumeSession;
use Swoolefy\Support\Rocketmq\RocketmqProducer;
use Swoolefy\Support\Rocketmq\RocketmqRetryLaterException;
use Swoolefy\Core\Sidecar\SidecarClient;

/**
 * 用一个假的 UDS sidecar 验证 ack / changeInvisible 的调用顺序和回执替换。
 */
final class RocketmqConsumeSessionSidecarTest extends CoroutineTestCase
{
    private string $dir = '';

    /** @var list<array{method: string, payload: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, array{code: string, message?: string, payload?: array<string, mixed>}> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Unix socket 路径上限约 104 字节（macOS），sys_get_temp_dir() 在 macOS 上太长。
        $this->dir = '/tmp/rmq-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        SidecarRegistry::reset();
        $this->requests = [];
        $this->replies = [
            'rocketmq.consumer.ack' => ['code' => 'OK'],
            'rocketmq.consumer.changeInvisible' => ['code' => 'OK', 'payload' => ['receiptHandle' => 'rh-2']],
        ];
    }

    protected function tearDown(): void
    {
        SidecarRegistry::reset();
        foreach (scandir($this->dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                @unlink($this->dir . '/' . $item);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testChangeInvisibleReplacesReceiptUsedByAck(): void
    {
        $this->runWithSidecar(function (): void {
            $session = $this->session();
            $message = $this->message();
            $session->changeInvisible($message, 60000);
            $this->assertSame('rh-2', $message->getReceiptHandle());
            $this->assertTrue($session->ack($message));

            $this->assertSame(['rocketmq.consumer.changeInvisible', 'rocketmq.consumer.ack'], $this->methods());
            $this->assertSame([
                'consumerGroup' => 'g',
                'topic' => 'order',
                'messageId' => 'mid',
                'receiptHandle' => 'rh-1',
                'invisibleMs' => 60000,
            ], $this->requests[0]['payload']);
            $this->assertSame('rh-2', $this->requests[1]['payload']['receiptHandle']);
        });
    }

    public function testChangeInvisibleClampsToProxyRange(): void
    {
        $this->runWithSidecar(function (): void {
            $session = $this->session();
            $session->changeInvisible($this->message(), 1000);
            $session->changeInvisible($this->message(), 900000);
            $this->assertSame(RocketmqConsumeSession::MIN_INVISIBLE_MS, $this->requests[0]['payload']['invisibleMs']);
            $this->assertSame(RocketmqConsumeSession::MAX_INVISIBLE_MS, $this->requests[1]['payload']['invisibleMs']);
        });
    }

    public function testDispatchAcksOnSuccess(): void
    {
        $this->runWithSidecar(function (): void {
            $acked = $this->session()->dispatch($this->message(), static function (): void {
            }, function (\Throwable $e): void {
                $this->fail($e->getMessage());
            });
            $this->assertTrue($acked);
            $this->assertSame(['rocketmq.consumer.ack'], $this->methods());
        });
    }

    public function testDispatchRetryLaterChangesInvisibleWithoutAck(): void
    {
        $this->runWithSidecar(function (): void {
            $errors = [];
            $acked = $this->session()->dispatch($this->message(), static function (): void {
                throw new RocketmqRetryLaterException(30000);
            }, static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e;
            });
            $this->assertFalse($acked);
            $this->assertSame([], $errors, 'retry later must not reach onHandleException');
            $this->assertSame(['rocketmq.consumer.changeInvisible'], $this->methods());
            $this->assertSame(30000, $this->requests[0]['payload']['invisibleMs']);
        });
    }

    public function testDispatchRetryLaterSurvivesChangeInvisibleFailure(): void
    {
        $this->replies['rocketmq.consumer.changeInvisible'] = ['code' => 'INVALID_ARGUMENT', 'message' => 'INVALID_RECEIPT_HANDLE'];
        $this->runWithSidecar(function (): void {
            $acked = $this->session()->dispatch($this->message(), static function (): void {
                throw new RocketmqRetryLaterException(30000);
            }, static function (): void {
            });
            $this->assertFalse($acked);
            $this->assertSame(['rocketmq.consumer.changeInvisible'], $this->methods());
        });
    }

    public function testDispatchOtherExceptionGoesToHandlerWithoutAck(): void
    {
        $this->runWithSidecar(function (): void {
            $errors = [];
            $acked = $this->session()->dispatch($this->message(), static function (): void {
                throw new \RuntimeException('boom');
            }, static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            });
            $this->assertFalse($acked);
            $this->assertSame(['boom'], $errors);
            $this->assertSame([], $this->methods());
        });
    }

    public function testExtendCurrentOnlyInsideDispatch(): void
    {
        $this->runWithSidecar(function (): void {
            $session = $this->session();
            $this->assertFalse($session->extendCurrent(60000));
            $this->assertSame([], $this->methods());

            $message = $this->message();
            $extended = null;
            $session->dispatch($message, static function () use ($session, &$extended): void {
                $extended = $session->extendCurrent(60000);
            }, static function (): void {
            });
            $this->assertTrue($extended);
            $this->assertSame(['rocketmq.consumer.changeInvisible', 'rocketmq.consumer.ack'], $this->methods());
            $this->assertSame('rh-2', $this->requests[1]['payload']['receiptHandle']);
        });
    }

    public function testProducerHintsOldSidecarWhenNewFieldsRejected(): void
    {
        $this->replies['rocketmq.producer.send'] = ['code' => 'INVALID_ARGUMENT', 'message' => 'payload'];
        $this->runWithSidecar(function (): void {
            $request = new SendMessageRequestDto();
            $request->setTopic('order');
            $request->setBodyBase64('aGk=');
            $request->setKeys(['ORDER_1']);
            try {
                (new RocketmqProducer(new SidecarClient()))->send($request, 1000);
                $this->fail('expected rejection');
            } catch (SidecarException $e) {
                $this->assertSame('INVALID_ARGUMENT', $e->sidecarCode);
                $this->assertStringContainsString('keys', $e->getMessage());
                $this->assertStringContainsString('upgrade go-sidecar', $e->getMessage());
            }
        });
    }

    public function testReceiveCarriesSubscriptionFilter(): void
    {
        $this->replies['rocketmq.consumer.receive'] = ['code' => 'OK', 'payload' => ['messages' => []]];
        $this->runWithSidecar(function (): void {
            (new RocketmqConsumeSession('order-events', 'stock', ['*'], 4, 30000, " eventType IN ('created','cancelled') "))->receive();
            (new RocketmqConsumeSession('order-events', 'billing', ['paid', 'cancel'], 4, 30000))->receive();

            $this->assertSame(['rocketmq.consumer.receive', 'rocketmq.consumer.receive'], $this->methods());
            $this->assertSame([
                'topic' => 'order-events',
                'consumerGroup' => 'stock',
                'consumerTag' => '*',
                'maxNum' => 4,
                'invisibleMs' => 30000,
                'consumerSql' => "eventType IN ('created','cancelled')",
            ], $this->requests[0]['payload']);
            $this->assertSame('paid || cancel', $this->requests[1]['payload']['consumerTag']);
            $this->assertArrayNotHasKey('consumerSql', $this->requests[1]['payload']);
        });
    }

    public function testReceiveHintsOldSidecarWhenSqlRejected(): void
    {
        $this->replies['rocketmq.consumer.receive'] = ['code' => 'INVALID_ARGUMENT', 'message' => 'payload'];
        $this->runWithSidecar(function (): void {
            try {
                (new RocketmqConsumeSession('order-events', 'stock', ['*'], 1, 30000, "eventType = 'created'"))->receive();
                $this->fail('expected rejection');
            } catch (SidecarException $e) {
                $this->assertSame('INVALID_ARGUMENT', $e->sidecarCode);
                $this->assertStringContainsString('consumerSql', $e->getMessage());
                $this->assertStringContainsString('upgrade go-sidecar', $e->getMessage());
            }
        });
    }

    public function testTagAndSqlAreExclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RocketmqConsumeSession('order-events', 'stock', ['paid'], 1, 30000, "eventType = 'created'");
    }

    private function session(): RocketmqConsumeSession
    {
        return new RocketmqConsumeSession('order', 'g', ['*'], 1, 30000);
    }

    private function message(): ReceivedMessageDto
    {
        return ReceivedMessageDto::fromArray([
            'messageId' => 'mid',
            'topic' => 'order',
            'bodyBase64' => 'aGk=',
            'receiptHandle' => 'rh-1',
            'deliveryAttempt' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(static fn (array $r): string => $r['method'], $this->requests);
    }

    private function runWithSidecar(callable $fn): void
    {
        $this->runInCoroutine(function () use ($fn): void {
            $path = $this->dir . '/rocketmq.sock';
            $server = new Socket(AF_UNIX, SOCK_STREAM, 0);
            $this->assertTrue($server->bind($path));
            $this->assertTrue($server->listen());
            $running = true;
            go(function () use ($server, &$running): void {
                while ($running) {
                    $conn = $server->accept(0.2);
                    if (!$conn instanceof Socket) {
                        continue;
                    }
                    go(function () use ($conn): void {
                        $req = json_decode($this->readFrame($conn), true, 512, JSON_THROW_ON_ERROR);
                        $this->requests[] = ['method' => $req['method'], 'payload' => $req['payload']];
                        $reply = $this->replies[$req['method']] ?? ['code' => 'METHOD_NOT_FOUND'];
                        $resp = json_encode([
                            'requestId' => $req['requestId'],
                            'code' => $reply['code'],
                            'message' => $reply['message'] ?? '',
                            'payload' => $reply['payload'] ?? new \stdClass(),
                        ], FrameCodec::JSON_FLAGS);
                        $bytes = FrameCodec::encode((string) $resp, 65536);
                        while ($bytes !== '') {
                            $n = $conn->send($bytes);
                            if (!is_int($n) || $n <= 0) {
                                break;
                            }
                            $bytes = substr($bytes, $n);
                        }
                        $conn->close();
                    });
                }
                $server->close();
            });
            SidecarRegistry::add(new SidecarSpec(
                name: 'rocketmq',
                binary: '/bin/true',
                args: [],
                socketPath: $path,
                maxFrameBytes: 65536,
                defaultTimeoutMs: 1000,
                maxTimeoutMs: 5000,
                maxInflight: 8,
                shutdownGraceMs: 100,
                plugins: 'rocketmq',
                environment: [],
            ));
            try {
                $fn();
            } finally {
                $running = false;
            }
        });
    }

    private function readFrame(Socket $conn): string
    {
        $header = '';
        while (strlen($header) < 4) {
            $chunk = $conn->recv(4 - strlen($header));
            $this->assertIsString($chunk);
            $header .= $chunk;
        }
        $length = (int) (unpack('Nlen', $header)['len'] ?? 0);
        $body = '';
        while (strlen($body) < $length) {
            $chunk = $conn->recv($length - strlen($body));
            $this->assertIsString($chunk);
            $body .= $chunk;
        }

        return $body;
    }
}
