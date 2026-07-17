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
 * | roles | 角色列表；HITL / 业务用 {@see hasRole()} / {@see isAdmin()} |
 * | tenantId | 可选租户；与 Header `x-tenant-id` 对齐 |
 * | claims | 其余 JWT claim 只读副本（不含已映射字段） |
 * | via | 来源：`jwt` / `api_key` / `system` 等，便于审计 |
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
     * @param string               $userId 用户主键
     * @param list<string>         $roles  角色名列表（精确匹配，区分大小写）
     * @param array<string, mixed> $claims 未映射到顶层字段的原始 claim
     * @param string               $via    凭证通道标识，默认 jwt
     */
    public function __construct(
        public string $userId,
        public array $roles = [],
        public ?string $tenantId = null,
        public array $claims = [],
        public string $via = 'jwt',
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

        return new self(
            userId: $userId,
            roles: array_values(array_map('strval', (array) ($data['roles'] ?? []))),
            tenantId: isset($data['tenantId']) && $data['tenantId'] !== ''
                ? (string) $data['tenantId']
                : null,
            claims: (array) ($data['claims'] ?? []),
            via: (string) ($data['via'] ?? 'jwt'),
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
            'roles' => $this->roles,
            'tenantId' => $this->tenantId,
            'via' => $this->via,
            'claims' => $this->claims,
        ];
    }

    /** 是否具备指定角色（in_array 严格比较）。 */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * 是否管理员。约定角色名 `admin`（与 WorkflowHitlAuth::ADMIN_ROLE 一致）。
     * admin 可跨 HITL assignee 操作他人任务。
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
