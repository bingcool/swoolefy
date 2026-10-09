<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoolefy\Exception\SystemException;

/**
 * Sidecar 启动、路径、帧和业务 code 失败。
 * 继承 SystemException 只为了沿用现有异常输出，调用方按本类捕获。
 */
class SidecarException extends SystemException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $sidecarCode = '',
    ) {
        parent::__construct($message, $code, $previous);
    }
}
