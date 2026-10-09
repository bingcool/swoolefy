<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

interface RocketmqMessageHandlerInterface
{
    public function handle(ReceivedMessageDto $message): void;
}
