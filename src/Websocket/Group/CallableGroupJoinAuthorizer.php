<?php

namespace Swoolefy\Websocket\Group;

/**
 * 将 callable / [实例, 方法] 适配为 GroupJoinAuthorizerInterface。
 *
 * ## callable 签名
 *
 * ```php
 * function (int $fd, string $userId, string $group, array $params): ?string
 * ```
 *
 * ## 返回值约定
 *
 * | 返回值 | 含义 |
 * |--------|------|
 * | null / true | 允许加组 |
 * | false | 拒绝，默认原因 `group join denied` |
 * | string | 拒绝，字符串为客户端可见原因 |
 *
 * @see GroupJoinAuthorizerInterface
 */
class CallableGroupJoinAuthorizer implements GroupJoinAuthorizerInterface
{
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function authorize(int $fd, string $userId, string $group, array $params): ?string
    {
        $result = ($this->callable)($fd, $userId, $group, $params);

        if ($result === null || $result === true) {
            return null;
        }

        if ($result === false) {
            return 'group join denied';
        }

        return is_string($result) ? $result : 'group join denied';
    }
}
