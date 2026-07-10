<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * 节点生命周期钩子名称常量（文档化顺序，见 docs/SwoolefyAI.md §4.2）。
 */
final class NodeLifecycle
{
    public const BEFORE_EXECUTE = 'beforeExecute';
    public const EXECUTE = 'execute';
    public const AFTER_EXECUTE = 'afterExecute';
    public const ON_RETRY = 'onRetry';
    public const ON_TIMEOUT = 'onTimeout';
    public const ON_PAUSE = 'onPause';
    public const ON_RESUME = 'onResume';
    public const ON_FAIL = 'onFail';
    public const COMPENSATE = 'compensate';

    /** @return list<string> 标准钩子调用顺序 */
    public static function orderedHooks(): array
    {
        return [
            self::BEFORE_EXECUTE,
            self::EXECUTE,
            self::AFTER_EXECUTE,
            self::ON_RETRY,
            self::ON_TIMEOUT,
            self::ON_PAUSE,
            self::ON_RESUME,
            self::ON_FAIL,
            self::COMPENSATE,
        ];
    }
}
