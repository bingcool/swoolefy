<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

/**
 * 节点可配置超时 —— WorkflowEngine TimeoutGuard 读取。
 *
 * 返回 0 表示使用引擎 defaultNodeTimeoutSeconds。
 */
interface ConfigurableTimeoutNodeInterface extends NodeInterface
{
    public function configuredTimeoutSeconds(): int;
}
