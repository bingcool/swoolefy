<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\SidecarClient;
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
    public function __construct(private readonly SidecarClient $client)
    {
    }

    public function send(SendMessageRequestDto $request, int $timeoutMs = 0): SendMessageResponseDto
    {
        $response = $this->client->call('rocketmq', 'rocketmq.producer.send', $request, $timeoutMs);
        $this->client->assertOk($response);

        return SendMessageResponseDto::fromArray($response->getPayload());
    }

    /**
     * 读取当前 rocketmq sidecar 二进制的发布版本。
     */
    public function version(int $timeoutMs = 0): string
    {
        return $this->client->version('rocketmq', $timeoutMs);
    }

    public function health(int $timeoutMs = 0): HealthCheckResponseDto
    {
        $response = $this->client->call('rocketmq', 'health.check', new HealthCheckRequestDto(), $timeoutMs);
        $this->client->assertOk($response);

        return HealthCheckResponseDto::fromArray($response->getPayload());
    }
}
