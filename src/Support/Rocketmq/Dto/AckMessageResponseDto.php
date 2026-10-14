<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.ack 成功时的响应，没有字段。失败由 SidecarClient::assertOk() 抛异常。
 */
final class AckMessageResponseDto extends AbstractDto
{
}
