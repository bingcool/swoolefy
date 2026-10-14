<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\Dto\SidecarResponseDto;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\HealthCheckRequestDto;
use Swoolefy\Support\Rocketmq\Dto\HealthCheckResponseDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageResponseDto;

/**
 * 通过名为 rocketmq 的 Sidecar 发送。bodyBase64 由调用方填好。
 * OutcomeUnknownException 不在这里吞掉，调用方按「可能已经发出」处理。
 */
final class RocketmqProducer
{
    /**
     * @param SidecarClient $client 调 rocketmq sidecar 的 UDS 客户端，每次调用新建连接
     */
    public function __construct(private readonly SidecarClient $client)
    {
    }

    /**
     * 发送一条消息，成功返回 broker 分配的 messageId。
     *
     * @param SendMessageRequestDto $request 消息内容；发送前会先调用 validate()
     * @param int $timeoutMs 本次调用超时，0 表示用 sidecar 配置的缺省值
     * @throws \InvalidArgumentException 字段互斥规则不满足（delayMs 与 deliverAtMs、messageGroup 与延时）
     * @throws SidecarException sidecar 返回非 OK；code 在 $sidecarCode
     */
    public function send(SendMessageRequestDto $request, int $timeoutMs = 0): SendMessageResponseDto
    {
        $request->validate();
        $response = $this->client->call('rocketmq', 'rocketmq.producer.send', $request, $timeoutMs);
        $this->assertSendOk($response, $request);

        return SendMessageResponseDto::fromArray($response->getPayload());
    }

    /**
     * 读取当前 rocketmq sidecar 二进制的发布版本。
     *
     * @param int $timeoutMs 本次调用超时，0 表示用缺省值
     */
    public function version(int $timeoutMs = 0): string
    {
        return $this->client->version('rocketmq', $timeoutMs);
    }

    /**
     * 调 health.check，读取 sidecar 的监听、Producer / Consumer 就绪状态、发送计数和能力列表。
     *
     * @param int $timeoutMs 本次调用超时，0 表示用缺省值
     * @throws SidecarException sidecar 返回非 OK
     */
    public function health(int $timeoutMs = 0): HealthCheckResponseDto
    {
        $response = $this->client->call('rocketmq', 'health.check', new HealthCheckRequestDto(), $timeoutMs);
        $this->client->assertOk($response);

        return HealthCheckResponseDto::fromArray($response->getPayload());
    }

    /**
     * 当前 sidecar 支持的能力，例如 send.keys、consumer.changeInvisible。
     * 不需要每次发送前调用；需要严格确认二进制版本的业务可以在启动时调一次。
     *
     * @param int $timeoutMs 本次调用超时，0 表示用缺省值
     * @return list<string>
     */
    public function capabilities(int $timeoutMs = 0): array
    {
        return $this->health($timeoutMs)->getCapabilities();
    }

    /**
     * sidecar 解析请求时拒绝不认识的字段，只回 INVALID_ARGUMENT / payload。
     * 请求里带了新字段时，多半是 sidecar 二进制没跟着 PHP 一起升级，把提示改得能直接定位。
     */
    private function assertSendOk(SidecarResponseDto $response, SendMessageRequestDto $request): void
    {
        try {
            $this->client->assertOk($response);
        } catch (SidecarException $exception) {
            $newFields = array_values(array_diff(array_keys($request->toArray()), ['topic', 'bodyBase64', 'tag', 'delayMs']));
            if ($exception->sidecarCode === SidecarResponseDto::CODE_INVALID_ARGUMENT
                && $exception->getMessage() === 'payload'
                && $newFields !== []
            ) {
                throw new SidecarException(
                    'rocketmq sidecar rejected payload; it may be too old to support fields: ' . implode(',', $newFields) . ', upgrade go-sidecar',
                    0,
                    $exception,
                    $exception->sidecarCode,
                );
            }
            throw $exception;
        }
    }
}
