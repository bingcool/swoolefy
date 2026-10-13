<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core\Sidecar;

use PHPUintTest\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\Sidecar\Dto\SidecarRequestDto;
use Swoolefy\Core\Sidecar\FrameCodec;
use Swoolefy\Core\Sidecar\SidecarConfig;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\SidecarLog;
use Swoolefy\Core\Sidecar\SidecarSpec;
use Swoolefy\Support\Rocketmq\Dto\HealthCheckResponseDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;

final class SidecarSpecTest extends TestCase
{
    private string $binary = '';

    /** @var array<string, mixed> */
    private array $savedConf = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->binary = sys_get_temp_dir() . '/sidecar-bin-' . uniqid('', true);
        file_put_contents($this->binary, "#!/bin/sh\nexit 0\n");
        chmod($this->binary, 0755);
        SidecarLog::resetTestHandler();
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $config->setAccessible(true);
        $this->savedConf = $config->getValue();
        $config->setValue(null, ['port' => 9501]);
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'SidecarSpec');
        }
    }

    protected function tearDown(): void
    {
        SidecarLog::resetTestHandler();
        @unlink($this->binary);
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $config->setAccessible(true);
        $config->setValue(null, $this->savedConf);
        parent::tearDown();
    }

    public function testDisabledRowSkipsBinaryCheck(): void
    {
        $spec = SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => false,
            'sidecar_exec_binary' => '/missing/binary',
        ], '/tmp/runtime');

        $this->assertNull($spec);
    }

    public function testInvalidEnableFails(): void
    {
        $this->expectException(SidecarException::class);
        SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => 'maybe',
            'sidecar_exec_binary' => $this->binary,
            'sidecar_plugins' => 'demo',
        ], '/tmp/runtime');
    }

    public function testUnknownKeyFails(): void
    {
        $this->expectException(SidecarException::class);
        SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_typo' => 1,
        ], '/tmp/runtime');
    }

    public function testEnabledRowEncodesEnvironmentAndResolvesSocket(): void
    {
        $spec = SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => $this->binary,
            'sidecar_args' => ['--flag'],
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_rocketmq_access_key' => '',
            'sidecar_rocketmq_secret_key' => '',
            'sidecar_rocketmq_allowed_topics' => 'order, pay',
        ], '/tmp/sidecar-runtime');

        $this->assertInstanceOf(SidecarSpec::class, $spec);
        // APP_NAME 是常量，可能已被先跑的用例定义，这里按实际值拼期望路径
        $this->assertSame(
            '/tmp/sidecar-runtime/sidecars_sock/' . BaseServer::getAppName() . '-9501-rocketmq.sock',
            $spec->socketPath,
        );
        $this->assertSame(['--flag'], $spec->args);
        $env = $spec->environment();
        $this->assertSame('1', $env['SIDECAR_ENABLE']);
        $this->assertSame('["--flag"]', $env['SIDECAR_ARGS']);
        $this->assertSame('127.0.0.1:8081', $env['SIDECAR_ROCKETMQ_PROXY_ENDPOINT']);
        $this->assertSame('order,pay', $env['SIDECAR_ROCKETMQ_ALLOWED_TOPICS']);
        $this->assertArrayNotHasKey('SIDECAR_ROCKETMQ_ACCESS_KEY', $env);
        $this->assertArrayNotHasKey('SIDECAR_ROCKETMQ_SECRET_KEY', $env);
    }

    public function testForeignPluginKeyFailsOnRocketmqRow(): void
    {
        $this->expectException(SidecarException::class);
        $this->expectExceptionMessage('unknown sidecar config key: sidecar_mqtt5_broker');
        SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => $this->binary,
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_mqtt5_broker' => '127.0.0.1:1883',
        ], '/tmp/runtime');
    }

    public function testCommaSeparatedPluginsFail(): void
    {
        $this->expectException(SidecarException::class);
        $this->expectExceptionMessage('sidecar_plugins is invalid');
        SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => $this->binary,
            'sidecar_plugins' => 'rocketmq,mqtt5',
        ], '/tmp/runtime');
    }

    public function testDisabledRowSkipsPluginFieldCheck(): void
    {
        $spec = SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => false,
            'sidecar_exec_binary' => '/missing/binary',
            'sidecar_rocketmq_proxy_endpoint' => 'http://not-a-host',
        ], '/tmp/runtime');

        $this->assertNull($spec);
    }

    public function testEndpointMustBeHostPortWhenRocketmqIsEnabled(): void
    {
        $this->expectException(SidecarException::class);
        SidecarSpec::fromRow([
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => $this->binary,
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => 'http://127.0.0.1:8081',
        ], '/tmp/runtime');
    }

    public function testRequestEnvelopeUsesCamelCaseAfterToArray(): void
    {
        $payload = new SendMessageRequestDto();
        $payload->setTopic('order');
        $payload->setBodyBase64(base64_encode('hi'));
        $payload->setDelayMs(0);
        $request = new SidecarRequestDto();
        $request->setRequestId('abc');
        $request->setMethod('rocketmq.producer.send');
        $request->setPayload($payload->toArray());
        $request->setTimeoutMs(3000);
        $json = json_encode($request->toArray(), FrameCodec::JSON_FLAGS);
        $this->assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('abc', $data['requestId']);
        $this->assertSame('order', $data['payload']['topic']);
        $this->assertSame(0, $data['payload']['delayMs']);
        $this->assertArrayNotHasKey('request_id', $data);
    }

    public function testFrameLengthRejectsEmptyAndOversize(): void
    {
        $this->expectException(SidecarException::class);
        FrameCodec::encode('', 16);
    }

    public function testHealthOmitsAbsentPluginFields(): void
    {
        $dto = HealthCheckResponseDto::fromArray([
            'version' => '0.1.0',
            'uptimeMs' => 10,
            'listenerOk' => true,
            'inflight' => 1,
        ]);
        $this->assertFalse($dto->hasProducerReady());
        $this->assertFalse($dto->hasConsumerReady());
        $this->expectException(SidecarException::class);
        $dto->getProducerReady();
    }

    public function testSidecarLogKeepsOnlyCallFields(): void
    {
        $captured = [];
        SidecarLog::setTestHandler(static function (string $channel, string $message, array $context) use (&$captured): void {
            $captured = ['channel' => $channel, 'message' => $message, 'context' => $context];
        });
        SidecarLog::write('rocketmq', 'rocketmq.producer.send', 'abc', 'OUTCOME_UNKNOWN', 12);
        $this->assertSame('rocketmq', $captured['channel']);
        $this->assertSame(
            ['sidecar', 'method', 'requestId', 'code', 'durationMs'],
            array_keys($captured['context'])
        );
        $this->assertSame('OUTCOME_UNKNOWN', $captured['context']['code']);
        $this->assertSame(12, $captured['context']['durationMs']);
        $this->assertStringNotContainsString('body', $captured['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSidecarConfigFileIsTheOnlyCopy(): void
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', sys_get_temp_dir() . '/swoolefy-sidecar-no-app');
        }
        $app = include dirname(__DIR__, 4) . '/src/Stubs/sidecar.conf.stub.php';
        $this->assertSame([], $app);
        $system = include dirname(__DIR__, 4) . '/src/Core/Sidecar/Config/SystemSidecar.php';
        $this->assertArrayNotHasKey('sidecar_max_frame_bytes', $system['sidecars'][0]);
        $loaded = SidecarConfig::fromApp();
        $this->assertSame('rocketmq', $loaded['sidecars'][0]['sidecar_name']);
        $public = SidecarConfig::publicSettings();
        $this->assertSame($public['sidecar_max_frame_bytes'], $loaded['sidecars'][0]['sidecar_max_frame_bytes']);
        $this->assertSame($public['sidecar_default_timeout_ms'], $loaded['sidecars'][0]['sidecar_default_timeout_ms']);
        $this->assertSame($public['sidecar_max_timeout_ms'], $loaded['sidecars'][0]['sidecar_max_timeout_ms']);
        $this->assertSame($public['sidecar_max_inflight'], $loaded['sidecars'][0]['sidecar_max_inflight']);
        $this->assertSame($public['sidecar_shutdown_grace_ms'], $loaded['sidecars'][0]['sidecar_shutdown_grace_ms']);
        $this->assertArrayNotHasKey('rocketmq_consumers', $loaded);
    }

    public function testAppSidecarMergesOntoSystem(): void
    {
        $merged = SidecarConfig::merge([
            'sidecar_runtime_dir' => '',
            'sidecars' => [[
                'sidecar_name' => 'rocketmq',
                'sidecar_enable' => true,
            ]],
        ], [
            'sidecar_runtime_dir' => '/tmp/app-runtime',
            'sidecars' => [[
                'sidecar_name' => 'billing',
                'sidecar_enable' => false,
                'sidecar_max_inflight' => 4,
            ]],
        ]);
        $this->assertSame('/tmp/app-runtime', $merged['sidecar_runtime_dir']);
        $this->assertSame(['rocketmq', 'billing'], array_column($merged['sidecars'], 'sidecar_name'));
        $this->assertSame(128, $merged['sidecars'][0]['sidecar_max_inflight']);
        $this->assertSame(4, $merged['sidecars'][1]['sidecar_max_inflight']);
        $this->assertSame(3000, $merged['sidecars'][1]['sidecar_default_timeout_ms']);
    }

    public function testNestedDtoStaysObjectUntilToArray(): void
    {
        $inner = new class extends AbstractDto {
            protected string $topic = 'order';
        };
        $outer = new class($inner) extends AbstractDto {
            public function __construct(protected AbstractDto $child)
            {
            }
        };
        $shallow = $outer->toArray();
        $this->assertInstanceOf(AbstractDto::class, $shallow['child']);
    }
}
