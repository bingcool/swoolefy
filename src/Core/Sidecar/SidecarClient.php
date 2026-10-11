<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\Sidecar\Dto\SidecarRequestDto;
use Swoolefy\Core\Sidecar\Dto\SidecarResponseDto;
use Swoolefy\Core\Sidecar\Dto\VersionRequestDto;

/**
 * Worker 协程内一次调用一条 UDS 连接。不保留 Client。
 */
final class SidecarClient
{
    public function call(string $name, string $method, AbstractDto $payload, int $timeoutMs): SidecarResponseDto
    {
        if (Coroutine::getCid() <= 0) {
            throw new SidecarException('sidecar call must run inside a coroutine');
        }

        // 读取声明配置
        $spec = SidecarRegistry::get($name);
        if ($timeoutMs <= 0) {
            $timeoutMs = $spec->defaultTimeoutMs;
        }
        if ($timeoutMs > $spec->maxTimeoutMs) {
            $timeoutMs = $spec->maxTimeoutMs;
        }
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $started = microtime(true);

        $request = new SidecarRequestDto();
        $request->setRequestId(bin2hex(random_bytes(16)));
        $request->setMethod($method);
        $body = $payload->toArray();
        self::assertPlainPayload($body);
        $request->setPayload($body);
        $request->setTimeoutMs($timeoutMs);

        $json = json_encode($request->toArray(), FrameCodec::JSON_FLAGS);
        if (!is_string($json)) {
            throw new SidecarException('sidecar request encode failed');
        }
        $frame = FrameCodec::encode($json, $spec->maxFrameBytes);

        $client = new Client(SWOOLE_SOCK_UNIX_STREAM);
        $wrote = false;
        try {
            $remain = $deadline - microtime(true);
            if ($remain <= 0 || !$client->connect($spec->socketPath, 0, $remain)) {
                throw new SidecarException('connect failed: ' . $name);
            }
            FrameCodec::writeAll($client, $frame, $deadline, $wrote);
            $raw = FrameCodec::readFrame($client, $spec->maxFrameBytes, $deadline);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new OutcomeUnknownException('response is not a json object');
            }
            $response = SidecarResponseDto::fromArray($data);
            if ($response->getRequestId() !== $request->getRequestId()) {
                throw new OutcomeUnknownException('requestId mismatch');
            }
            SidecarLog::write($name, $method, $request->getRequestId(), $response->getCode(), self::elapsed($started));

            return $response;
        } catch (OutcomeUnknownException $e) {
            SidecarLog::write($name, $method, $request->getRequestId(), SidecarResponseDto::CODE_OUTCOME_UNKNOWN, self::elapsed($started));
            throw $e;
        } catch (\Throwable $e) {
            SidecarLog::write(
                $name,
                $method,
                $request->getRequestId(),
                $e instanceof SidecarException && $e->sidecarCode !== '' ? $e->sidecarCode : 'ERROR',
                self::elapsed($started),
            );
            if ($wrote) {
                throw new OutcomeUnknownException($e->getMessage(), 0, $e);
            }
            if ($e instanceof SidecarException) {
                throw $e;
            }
            throw new SidecarException($e->getMessage(), 0, $e);
        } finally {
            $client->close();
        }
    }

    /**
     * 读取 sidecar 进程的发布版本。版本号定义在 Go 的 internal/version.Version。
     */
    public function version(string $name, int $timeoutMs = 0): string
    {
        $response = $this->call($name, 'sidecar.version', new VersionRequestDto(), $timeoutMs);
        $this->assertOk($response);
        $version = $response->getPayload()['version'] ?? '';
        if (!is_string($version) || $version === '') {
            throw new SidecarException('sidecar version is absent');
        }

        return $version;
    }

    public function assertOk(SidecarResponseDto $response): void
    {
        $code = $response->getCode();
        if ($code === SidecarResponseDto::CODE_OK) {
            return;
        }
        $message = $response->getMessage() !== '' ? $response->getMessage() : 'sidecar call failed';
        if ($code === SidecarResponseDto::CODE_OUTCOME_UNKNOWN) {
            throw new OutcomeUnknownException($message, 0, null, $code);
        }
        if (!in_array($code, SidecarResponseDto::CODES, true)) {
            throw new SidecarException($message, 0, null, $code);
        }
        throw new SidecarException($message, 0, null, $code);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertPlainPayload(array $payload): void
    {
        foreach ($payload as $value) {
            if (is_object($value)) {
                throw new SidecarException('sidecar payload must be a plain array; call toArray() on nested DTOs first');
            }
            if (is_array($value)) {
                self::assertPlainPayload($value);
            }
        }
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
