<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue;

use Swoolefy\Exception\SystemException;

/**
 * 队列组件缺失、组件名为空，或当前没有 Application 时抛出。
 */
class QueueException extends SystemException
{
}
