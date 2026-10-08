<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin\Builtin;

use Swoolefy\Support\FrameworkContext;
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
 *   - effectiveRoles 为空 → 不限制，且不调用 {@see \Swoolefy\Support\Auth\AuthUser::roles()}
 *   - 否则要求 FrameworkContext::user() 非空，且 roles() 与 effectiveRoles 有交集
 *   - input 里的 role / roles / tenant 不参与判断
 *
 * $roleKey / $tenantKey 只保留构造签名，不再读取 input。
 *
 * @see docs/SwoolefyAI.md §4.12 PermissionPlugin
 */
final class PermissionPlugin implements WorkflowPluginInterface
{
    /**
     * @param list<string> $allowedRoles 全局允许角色；空表示仅依赖 Definition metadata
     * @param string       $roleKey      保留参数，不再从 input 读取
     * @param string       $tenantKey    保留参数，租户只取 AuthUser::$tenantId
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
            unset($input);

            $metadataRoles = $run->compiled->metadata()['allowedRoles'] ?? [];
            $effectiveRoles = $this->allowedRoles;
            if (is_array($metadataRoles) && $metadataRoles !== []) {
                $effectiveRoles = array_values(array_unique([...$effectiveRoles, ...$metadataRoles]));
            }

            if ($effectiveRoles === []) {
                return;
            }

            $user = FrameworkContext::user();
            if ($user === null) {
                throw new WorkflowPermissionException('Insufficient role for workflow run');
            }

            foreach ($effectiveRoles as $role) {
                if (is_string($role) && $user->hasRole($role)) {
                    return;
                }
            }

            $tenantId = $user->tenantId;
            throw new WorkflowPermissionException(
                'Insufficient role for workflow run'
                . ($tenantId !== null && $tenantId !== '' ? " (tenant={$tenantId})" : ''),
            );
        });
    }
}
