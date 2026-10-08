<?php

declare(strict_types=1);

namespace Swoolefy\Support\Auth;

/**
 * 当前用户的服务端角色源。
 *
 * 组件名 `auth.role_resolver`。框架不内置用户表查询，由应用注册实现。
 * 验票成功不会调用它；只有 {@see AuthUser::roles()} 第一次读取时才调用。
 * 空数组表示该用户当前没有任何角色，不是失败。
 */
interface RoleResolverInterface
{
    /**
     * @return list<string>
     */
    public function currentRoles(string $userId): array;
}
