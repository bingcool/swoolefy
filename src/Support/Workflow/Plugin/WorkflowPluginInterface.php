<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin;

/**
 * 工作流插件接口 —— 扩展 Retry、Metrics、Tracing 等横切能力。
 */
interface WorkflowPluginInterface
{
    /** 插件唯一名称，用于日志与配置。 */
    public function name(): string;

    /** 向注册表挂载钩子。 */
    public function register(PluginRegistry $registry): void;
}
