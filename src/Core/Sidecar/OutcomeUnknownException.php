<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

/**
 * 请求字节已经写出，对端结果不能确定。调用方不能自动再发一次。
 */
class OutcomeUnknownException extends SidecarException
{
}
