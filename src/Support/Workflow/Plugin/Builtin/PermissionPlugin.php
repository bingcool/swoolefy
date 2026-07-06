<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin\Builtin;

use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;

/**
 * Workflow Run 权限插件 —— 启动前校验调用者角色。
 *
 * 有效角色集 = 插件构造 allowedRoles ∪ WorkflowDefinition.metadata.allowedRoles
 *
 * 校验规则：
 *   - effectiveRoles 为空 → 不限制（开放 Run）
 *   - input[roleKey] 必须在 effectiveRoles 内，否则抛 WorkflowPermissionException
 *
 * 典型 input：{ "role": "operator", "tenantId": "t1", ... }
 *
 * @see docs/swoolefyAI.md §4.12 PermissionPlugin
 */
final class PermissionPlugin implements WorkflowPluginInterface
{
    /**
     * @param list<string> $allowedRoles 全局允许角色；空表示仅依赖 Definition metadata
     * @param string       $roleKey      input 中角色字段名，默认 role
     * @param string       $tenantKey    input 中租户字段名（错误信息用），默认 tenantId
     */
    public function __construct(
        private readonly array $allowedRoles = [],
        private readonly string $roleKey = 'role',
        private readonly string $tenantKey = 'tenantId',
    ) {
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'permission';
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $role = $input[$this->roleKey] ?? null;
            $tenantId = $input[$this->tenantKey] ?? null;

            $metadataRoles = $run->compiled->metadata()['allowedRoles'] ?? [];
            $effectiveRoles = $this->allowedRoles;
            if (is_array($metadataRoles) && $metadataRoles !== []) {
                $effectiveRoles = array_values(array_unique([...$effectiveRoles, ...$metadataRoles]));
            }

            if ($effectiveRoles === []) {
                return;
            }

            if (!is_string($role) || $role === '' || !in_array($role, $effectiveRoles, true)) {
                throw new WorkflowPermissionException(
                    'Insufficient role for workflow run'
                    . ($tenantId !== null ? " (tenant={$tenantId})" : ''),
                );
            }
        });
    }
}
