<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin;

/**
 * 插件钩子注册表 —— {@see WorkflowPluginInterface} 在此注册回调。
 *
 * 可用钩子点见 docs/swoolefyAI.md §3.5 表格。
 */
final class PluginRegistry
{
    /** @var list<callable> */
    private array $onEngineStart = [];

    /** @var list<callable> */
    private array $onEngineStop = [];

    /** @var list<callable> */
    private array $onRunStart = [];

    /** @var list<callable> */
    private array $onRunComplete = [];

    /** @var list<callable> */
    private array $onNodeBefore = [];

    /** @var list<callable> */
    private array $onNodeAfter = [];

    /** @var list<callable> */
    private array $onNodeFail = [];

    /** @var list<callable> */
    private array $onPause = [];

    /** @var list<callable> */
    private array $onResume = [];

    /** 引擎启动时（进程级，Phase 2+）。 */
    public function onEngineStart(callable $hook): void
    {
        $this->onEngineStart[] = $hook;
    }

    /** 引擎停止时。 */
    public function onEngineStop(callable $hook): void
    {
        $this->onEngineStop[] = $hook;
    }

    /** 单次 Run 开始。 */
    public function onRunStart(callable $hook): void
    {
        $this->onRunStart[] = $hook;
    }

    /** 单次 Run 结束（成功/失败/取消）。 */
    public function onRunComplete(callable $hook): void
    {
        $this->onRunComplete[] = $hook;
    }

    /** 节点 execute 之前。 */
    public function onNodeBefore(callable $hook): void
    {
        $this->onNodeBefore[] = $hook;
    }

    /** 节点 execute 之后（含 RETRY，不含 FAILED 专用钩子）。 */
    public function onNodeAfter(callable $hook): void
    {
        $this->onNodeAfter[] = $hook;
    }

    /** 节点 FAILED 时。 */
    public function onNodeFail(callable $hook): void
    {
        $this->onNodeFail[] = $hook;
    }

    /** 进入 WAITING 暂停时。 */
    public function onPause(callable $hook): void
    {
        $this->onPause[] = $hook;
    }

    /** resume 恢复时。 */
    public function onResume(callable $hook): void
    {
        $this->onResume[] = $hook;
    }

    /**
     * 按事件名获取已注册的钩子列表。
     *
     * @return list<callable>
     */
    public function hooks(string $event): array
    {
        return match ($event) {
            'engine.start' => $this->onEngineStart,
            'engine.stop' => $this->onEngineStop,
            'run.start' => $this->onRunStart,
            'run.complete' => $this->onRunComplete,
            'node.before' => $this->onNodeBefore,
            'node.after' => $this->onNodeAfter,
            'node.fail' => $this->onNodeFail,
            'pause' => $this->onPause,
            'resume' => $this->onResume,
            default => [],
        };
    }
}
