<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\State;

use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 单次工作流运行的可变状态容器（Phase 1 内存；生产可序列化到 Redis）。
 *
 * 字段说明：
 *   data         — 业务数据（输入、AI 决策、HITL feedback 等）
 *   nodeOutputs  — 各节点原始输出，key 为 nodeId
 *   agentOutputs — 多 Agent 并行结果（Phase 2+）
 *   meta         — 引擎内部元数据
 *   schemas      — outputKey → DTO 类，供 dto() 反序列化
 *
 * 节点内推荐使用 Typed API：
 *   $state->dto(OrderDecisionDto::class)
 *   $state->outputOf('ai_decision')
 *
 * 条件边通过 Symfony EL 读 data：data['decision']['approved']
 *
 * @see docs/SwoolefyAI.md §3.2、§3.4
 */
final class WorkflowState
{
    public function __construct(
        /** @var array<string, mixed> 业务数据 */
        public array $data = [],
        /** @var array<string, mixed> 节点输出 */
        public array $nodeOutputs = [],
        /** @var array<string, mixed> Agent 输出 */
        public array $agentOutputs = [],
        /** @var array<string, mixed> 元数据 */
        public array $meta = [],
        /** @var array<string, class-string> schema 映射 */
        private array $schemas = [],
    ) {
    }

    /**
     * 从 HTTP/CLI 输入创建初始状态。
     *
     * @param array<string, mixed>        $input   初始 data
     * @param array<string, class-string> $schemas 来自 CompiledWorkflow
     */
    public static function fromInput(array $input, array $schemas = []): self
    {
        return new self(data: $input, schemas: $schemas);
    }

    /** 运行时附加 schema 映射。 */
    public function withSchemas(array $schemas): self
    {
        $this->schemas = $schemas;

        return $this;
    }

    /** 读取 data 中的键，不存在返回 default。 */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** 写入 data 键值。 */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /** 合并 payload 到 data（resume 时合并 feedback 等）。 */
    public function mergeData(array $payload): void
    {
        $this->data = array_replace($this->data, $payload);
    }

    /** 获取指定节点的输出。 */
    public function outputOf(string $nodeId): mixed
    {
        return $this->nodeOutputs[$nodeId] ?? null;
    }

    /** 引擎写入节点输出。 */
    public function setNodeOutput(string $nodeId, mixed $output): void
    {
        $this->nodeOutputs[$nodeId] = $output;
    }

    /** 获取指定 Agent 的输出（Phase 2+）。 */
    public function agentOutput(string $agentId): mixed
    {
        return $this->agentOutputs[$agentId] ?? null;
    }

    /** 写入 Agent 并行输出。 */
    public function setAgentOutput(string $agentId, mixed $output): void
    {
        $this->agentOutputs[$agentId] = $output;
    }

    /**
     * 按 registerSchema 映射将 data 反序列化为 DTO 对象。
     *
     * @param class-string $class 如 OrderDecisionDto::class
     */
    public function dto(string $class): object
    {
        foreach ($this->schemas as $key => $schemaClass) {
            if ($schemaClass !== $class || !array_key_exists($key, $this->data)) {
                continue;
            }

            return $this->hydrateDto($class, $this->data[$key]);
        }

        foreach ($this->data as $value) {
            if ($value instanceof $class) {
                return $value;
            }
        }

        throw new WorkflowException("DTO not found for class {$class}");
    }

    /** 序列化为数组，供 RunStore / Redis 持久化。 */
    public function toArray(): array
    {
        return [
            'data' => $this->serializeValue($this->data),
            'nodeOutputs' => $this->serializeValue($this->nodeOutputs),
            'agentOutputs' => $this->serializeValue($this->agentOutputs),
            'meta' => $this->meta,
            'schemas' => $this->schemas,
        ];
    }

    /** 从持久化数组恢复状态。 */
    public static function fromArray(array $payload): self
    {
        return new self(
            data: $payload['data'] ?? [],
            nodeOutputs: $payload['nodeOutputs'] ?? [],
            agentOutputs: $payload['agentOutputs'] ?? [],
            meta: $payload['meta'] ?? [],
            schemas: $payload['schemas'] ?? [],
        );
    }

    /** 将数组/对象 hydrate 为 DTO 实例。 */
    private function hydrateDto(string $class, mixed $value): object
    {
        if ($value instanceof $class) {
            return $value;
        }

        if (!is_array($value) && !is_object($value)) {
            throw new WorkflowException("Cannot hydrate {$class} from scalar value");
        }

        $dto = new $class();
        $source = (array) $value;

        foreach ($source as $property => $propertyValue) {
            if (property_exists($dto, $property)) {
                $dto->{$property} = $propertyValue;
            }
        }

        return $dto;
    }

    /** 递归序列化，支持 JsonSerializable 与 object。 */
    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_array($value)) {
            $serialized = [];
            foreach ($value as $key => $item) {
                $serialized[$key] = $this->serializeValue($item);
            }

            return $serialized;
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        return $value;
    }
}
