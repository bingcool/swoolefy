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

namespace Swoolefy\Support\Auth;

use Swoolefy\Core\Application;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Support\FrameworkContext;

/**
 * 请求态身份值对象（不可变）。
 *
 * ## 为何用值对象 + array 快照
 * Swoole Worker 常驻进程下，禁止用 static / 进程单例挂「当前用户」，否则协程并发会串身份。
 * {@see goApp()} 拷贝 Context 时会 **跳过 object**，
 * 因此协程 Context 中只能存 {@see toArray()} 的 array，读时再用 {@see fromArray()} 还原。
 *
 * ## 字段说明
 * | 字段 | 含义 |
 * |------|------|
 * | userId | 用户主键（JWT `uid`/`sub` 或系统身份 id） |
 * | roles | 已加载的角色列表。未加载时为空，**不能**当作授权结果 |
 * | rolesResolved | false：尚未向角色源加载；true：`$roles` 是本次请求的授权结果 |
 * | tenantId | 可选租户；与 Header `x-tenant-id` 对齐 |
 * | claims | 其余 JWT claim 只读副本（不含已映射字段） |
 * | via | 来源：`jwt` / `api_key` / `system` 等，便于审计 |
 *
 * JWT 有效只说明身份凭证通过。授权角色只通过 {@see roles()} 读取：
 * 第一次调用才向组件 `auth.role_resolver` 加载，并回写本请求 Context 快照。
 * 禁止授权代码直接读 `$user->roles`（未加载时它是空数组）。
 *
 * ## 禁止
 * - 放入进程级 static / 单例属性
 * - 直接 `Context::set('…', $authUserObject)`（子协程会丢身份）
 *
 * @see \Swoolefy\Support\FrameworkContext::setUser()
 * @see docs/Auth.md
 */
final readonly class AuthUser
{
    /**
     * @param string               $userId         用户主键
     * @param list<string>         $roles          仅当 $rolesResolved 为 true 时作为授权结果
     * @param array<string, mixed> $claims         未映射到顶层字段的原始 claim
     * @param string               $via            凭证通道标识，默认 jwt
     * @param bool                 $rolesResolved  true 表示 $roles 已是服务端授权结果
     */
    public function __construct(
        public string $userId,
        public array $roles = [],
        public ?string $tenantId = null,
        public array $claims = [],
        public string $via = 'jwt',
        public bool $rolesResolved = false,
    ) {
    }

    /**
     * 从 Context 快照还原。userId 为空视为损坏数据，抛 500（非客户端 401）。
     *
     * @param array<string, mixed> $data {@see toArray()} 的结构
     */
    public static function fromArray(array $data): self
    {
        $userId = (string) ($data['userId'] ?? '');
        if ($userId === '') {
            throw new AuthException('AuthUser userId is required', 500);
        }

        $rolesRaw = $data['roles'] ?? null;
        $rolesResolved = is_array($rolesRaw);

        return new self(
            userId: $userId,
            roles: $rolesResolved ? array_values(array_map('strval', $rolesRaw)) : [],
            tenantId: isset($data['tenantId']) && $data['tenantId'] !== ''
                ? (string) $data['tenantId']
                : null,
            claims: (array) ($data['claims'] ?? []),
            via: (string) ($data['via'] ?? 'jwt'),
            rolesResolved: $rolesResolved,
        );
    }

    /**
     * 写入协程 Context 的可拷贝快照（仅标量/数组，无 object）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            // null = 本请求尚未加载；空数组 = 已加载且当前没有任何角色
            'roles' => $this->rolesResolved ? array_values($this->roles) : null,
            'rolesResolved' => $this->rolesResolved,
            'tenantId' => $this->tenantId,
            'via' => $this->via,
            'claims' => $this->claims,
        ];
    }

    /**
     * 本次请求采用的服务端角色。未加载时向 auth.role_resolver 读取一次并回写快照。
     *
     * @return list<string>
     */
    public function roles(): array
    {
        if ($this->userId === '') {
            throw new AuthException('AuthUser userId is required', 500);
        }

        if ($this->rolesResolved) {
            return array_values($this->roles);
        }

        $snapshot = FrameworkContext::userSnapshot();
        if (is_array($snapshot)
            && ($snapshot['userId'] ?? '') === $this->userId
            && isset($snapshot['roles'])
            && is_array($snapshot['roles'])
        ) {
            return array_values(array_map('strval', $snapshot['roles']));
        }

        $resolved = $this->loadRolesFromResolver();
        FrameworkContext::rememberResolvedRoles($this->userId, $resolved, $this);

        return $resolved;
    }

    /** 是否具备指定角色（in_array 严格比较）。 */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * 是否管理员。约定角色名 `admin`（与 WorkflowHitlAuth::ADMIN_ROLE 一致）。
     * admin 可跨 HITL assignee 操作他人任务。
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * @return list<string>
     */
    private function loadRolesFromResolver(): array
    {
        $app = Application::getApp();
        $component = is_object($app) ? $app->get('auth.role_resolver') : false;
        if ($component instanceof ContainerObjectDto) {
            $component = $component->getObject();
        }
        if (!$component instanceof RoleResolverInterface) {
            throw new AuthException('auth.role_resolver is not registered', 500);
        }

        $roles = $component->currentRoles($this->userId);
        if (!is_array($roles) || !array_is_list($roles)) {
            throw new AuthException('auth.role_resolver returned invalid roles', 500);
        }
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new AuthException('auth.role_resolver returned invalid roles', 500);
            }
        }

        return array_values($roles);
    }
}
