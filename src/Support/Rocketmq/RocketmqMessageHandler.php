<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

/**
 * 默认实现拒绝处理，避免没有业务函数时把消息直接确认掉。
 * 应用在启动前 {@see register()} 自己的实现，并按 messageId 做幂等。
 */
final class RocketmqMessageHandler implements RocketmqMessageHandlerInterface
{
    private static ?RocketmqMessageHandlerInterface $delegate = null;

    public static function register(RocketmqMessageHandlerInterface $handler): void
    {
        self::$delegate = $handler;
    }

    public static function reset(): void
    {
        self::$delegate = null;
    }

    public function handle(ReceivedMessageDto $message): void
    {
        if (self::$delegate === null) {
            throw new SidecarException('rocketmq message handler is not registered');
        }
        self::$delegate->handle($message);
    }
}
