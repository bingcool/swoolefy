<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\Sidecar\SidecarException;

/**
 * 缺键保持未初始化。getter 在字段未出现时抛错，避免把「没有该字段」读成 false 或 0。
 */
final class HealthCheckResponseDto extends AbstractDto
{
    protected string $version = '';

    protected int $uptimeMs = 0;

    protected bool $listenerOk = false;

    protected int $inflight = 0;

    protected bool $producerReady;

    protected bool $consumerReady;

    protected int $sendOk;

    protected int $sendFail;

    protected int $sendTimeout;

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setUptimeMs(int $uptimeMs): void
    {
        $this->uptimeMs = $uptimeMs;
    }

    public function getUptimeMs(): int
    {
        return $this->uptimeMs;
    }

    public function setListenerOk(bool $listenerOk): void
    {
        $this->listenerOk = $listenerOk;
    }

    public function getListenerOk(): bool
    {
        return $this->listenerOk;
    }

    public function setInflight(int $inflight): void
    {
        $this->inflight = $inflight;
    }

    public function getInflight(): int
    {
        return $this->inflight;
    }

    public function hasProducerReady(): bool
    {
        return isset($this->producerReady);
    }

    public function setProducerReady(bool $producerReady): void
    {
        $this->producerReady = $producerReady;
    }

    public function getProducerReady(): bool
    {
        if (!isset($this->producerReady)) {
            throw new SidecarException('producerReady is absent');
        }

        return $this->producerReady;
    }

    public function hasConsumerReady(): bool
    {
        return isset($this->consumerReady);
    }

    public function setConsumerReady(bool $consumerReady): void
    {
        $this->consumerReady = $consumerReady;
    }

    public function getConsumerReady(): bool
    {
        if (!isset($this->consumerReady)) {
            throw new SidecarException('consumerReady is absent');
        }

        return $this->consumerReady;
    }

    public function hasSendOk(): bool
    {
        return isset($this->sendOk);
    }

    public function setSendOk(int $sendOk): void
    {
        $this->sendOk = $sendOk;
    }

    public function getSendOk(): int
    {
        if (!isset($this->sendOk)) {
            throw new SidecarException('sendOk is absent');
        }

        return $this->sendOk;
    }

    public function hasSendFail(): bool
    {
        return isset($this->sendFail);
    }

    public function setSendFail(int $sendFail): void
    {
        $this->sendFail = $sendFail;
    }

    public function getSendFail(): int
    {
        if (!isset($this->sendFail)) {
            throw new SidecarException('sendFail is absent');
        }

        return $this->sendFail;
    }

    public function hasSendTimeout(): bool
    {
        return isset($this->sendTimeout);
    }

    public function setSendTimeout(int $sendTimeout): void
    {
        $this->sendTimeout = $sendTimeout;
    }

    public function getSendTimeout(): int
    {
        if (!isset($this->sendTimeout)) {
            throw new SidecarException('sendTimeout is absent');
        }

        return $this->sendTimeout;
    }
}
