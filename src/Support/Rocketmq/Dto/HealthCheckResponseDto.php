<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\Sidecar\SidecarException;

/**
 * health.check 的响应。
 *
 * 缺键保持未初始化。getter 在字段未出现时抛错，避免把「没有该字段」读成 false 或 0。
 */
final class HealthCheckResponseDto extends AbstractDto
{
    /**
     * sidecar 二进制版本，与 sidecar.version 相同。
     */
    protected string $version = '';

    /**
     * sidecar 进程已运行的毫秒数。
     */
    protected int $uptimeMs = 0;

    /**
     * UDS 监听是否正常。
     */
    protected bool $listenerOk = false;

    /**
     * 当前在途请求数（包括阻塞中的 receive）。
     */
    protected int $inflight = 0;

    /**
     * Producer 是否已 Start 成功。只有注册了 rocketmq.producer.send 才返回。
     */
    protected bool $producerReady;

    /**
     * 消费插件和 proxy 配置是否可用，不表示某个消费组已订阅。只有注册了 rocketmq.consumer.receive 才返回。
     */
    protected bool $consumerReady;

    /**
     * 累计发送成功次数。
     */
    protected int $sendOk;

    /**
     * 累计发送失败次数。
     */
    protected int $sendFail;

    /**
     * 累计发送超时次数。
     */
    protected int $sendTimeout;

    /**
     * sidecar 支持的能力，例如 send.keys、consumer.changeInvisible、subscribe.sql92。旧版 sidecar 没有这个字段。
     *
     * @var list<string>
     */
    protected array $capabilities;

    /**
     * 设置版本号。
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * 读取版本号。
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * 设置运行时长（毫秒）。
     */
    public function setUptimeMs(int $uptimeMs): void
    {
        $this->uptimeMs = $uptimeMs;
    }

    /**
     * 读取运行时长（毫秒）。
     */
    public function getUptimeMs(): int
    {
        return $this->uptimeMs;
    }

    /**
     * 设置监听状态。
     */
    public function setListenerOk(bool $listenerOk): void
    {
        $this->listenerOk = $listenerOk;
    }

    /**
     * 读取监听状态。
     */
    public function getListenerOk(): bool
    {
        return $this->listenerOk;
    }

    /**
     * 设置在途请求数。
     */
    public function setInflight(int $inflight): void
    {
        $this->inflight = $inflight;
    }

    /**
     * 读取在途请求数。
     */
    public function getInflight(): int
    {
        return $this->inflight;
    }

    /**
     * 响应里是否有 producerReady。
     */
    public function hasProducerReady(): bool
    {
        return isset($this->producerReady);
    }

    /**
     * 设置 Producer 就绪状态。
     */
    public function setProducerReady(bool $producerReady): void
    {
        $this->producerReady = $producerReady;
    }

    /**
     * 读取 Producer 就绪状态。
     *
     * @throws SidecarException 响应里没有该字段
     */
    public function getProducerReady(): bool
    {
        if (!isset($this->producerReady)) {
            throw new SidecarException('producerReady is absent');
        }

        return $this->producerReady;
    }

    /**
     * 响应里是否有 consumerReady。
     */
    public function hasConsumerReady(): bool
    {
        return isset($this->consumerReady);
    }

    /**
     * 设置消费插件就绪状态。
     */
    public function setConsumerReady(bool $consumerReady): void
    {
        $this->consumerReady = $consumerReady;
    }

    /**
     * 读取消费插件就绪状态。
     *
     * @throws SidecarException 响应里没有该字段
     */
    public function getConsumerReady(): bool
    {
        if (!isset($this->consumerReady)) {
            throw new SidecarException('consumerReady is absent');
        }

        return $this->consumerReady;
    }

    /**
     * 响应里是否有 sendOk。
     */
    public function hasSendOk(): bool
    {
        return isset($this->sendOk);
    }

    /**
     * 设置发送成功次数。
     */
    public function setSendOk(int $sendOk): void
    {
        $this->sendOk = $sendOk;
    }

    /**
     * 读取发送成功次数。
     *
     * @throws SidecarException 响应里没有该字段
     */
    public function getSendOk(): int
    {
        if (!isset($this->sendOk)) {
            throw new SidecarException('sendOk is absent');
        }

        return $this->sendOk;
    }

    /**
     * 响应里是否有 sendFail。
     */
    public function hasSendFail(): bool
    {
        return isset($this->sendFail);
    }

    /**
     * 设置发送失败次数。
     */
    public function setSendFail(int $sendFail): void
    {
        $this->sendFail = $sendFail;
    }

    /**
     * 读取发送失败次数。
     *
     * @throws SidecarException 响应里没有该字段
     */
    public function getSendFail(): int
    {
        if (!isset($this->sendFail)) {
            throw new SidecarException('sendFail is absent');
        }

        return $this->sendFail;
    }

    /**
     * 响应里是否有 sendTimeout。
     */
    public function hasSendTimeout(): bool
    {
        return isset($this->sendTimeout);
    }

    /**
     * 设置发送超时次数。
     */
    public function setSendTimeout(int $sendTimeout): void
    {
        $this->sendTimeout = $sendTimeout;
    }

    /**
     * 读取发送超时次数。
     *
     * @throws SidecarException 响应里没有该字段
     */
    public function getSendTimeout(): int
    {
        if (!isset($this->sendTimeout)) {
            throw new SidecarException('sendTimeout is absent');
        }

        return $this->sendTimeout;
    }

    /**
     * 响应里是否有 capabilities。旧版 sidecar 没有。
     */
    public function hasCapabilities(): bool
    {
        return isset($this->capabilities);
    }

    /**
     * 设置能力列表。
     *
     * @param list<string> $capabilities
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * 旧版 sidecar 不返回该字段，这里返回空数组，表示「不支持任何新能力」。
     *
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities ?? [];
    }
}
