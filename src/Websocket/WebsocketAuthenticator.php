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

namespace Swoolefy\Websocket;

use Swoole\Http\Request;

/**
 * WebSocket 握手鉴权（在 `WebsocketServer::onOpen` 中调用）。
 *
 * ## 调用时机
 *
 * ```
 * 客户端 WebSocket 升级成功
 *   → WebsocketAuthenticator::authenticate()
 *   → 失败：disconnect(1008)
 *   → 成功：WebsocketConnectionManager::open(user_id=...)
 * ```
 *
 * ## 配置（Config/websocket.php → auth）
 *
 * | 字段 | 说明 |
 * |------|------|
 * | enable | 是否开启鉴权；false 时仅读 query uid（开发模式） |
 * | require_user_id | enable=true 时默认 true：禁止匿名连接 |
 * | tokens | 静态 token 白名单（与 callback 二选一） |
 * | callback | 推荐生产使用：校验 JWT/Session 并返回 user_id |
 *
 * ## require_user_id 与 pushToUser
 *
 * `pushToUser` 依赖 Redis/Swoole Table 的 `user:{user_id}` 索引。
 * 匿名连接（user_id 为空）无法被点对点推送；开启鉴权后强制绑定 user_id，
 * 与 `WebsocketService::pushToUser()` / `ExternalPushPublisher::pushToUser()` 的空值校验配合。
 *
 * ## Token 提取优先级
 *
 * 1. `Authorization: Bearer {token}`
 * 2. Query：`token` / `access_token`
 * 3. Header：`Sec-WebSocket-Protocol`（部分浏览器/SDK 放 token 于此）
 *
 * ## callback 返回值
 *
 * - `false`：拒绝连接
 * - `true`：通过，user_id 从 query `uid`/`user_id` 读取
 * - `['user_id' => 'xxx']`：通过并绑定该 user_id（推荐，可覆盖 query）
 *
 * @see WebsocketServer  onOpen 握手入口
 * @see ClusterConfig::authSettings()
 */
class WebsocketAuthenticator
{
    /**
     * 执行握手鉴权。
     *
     * @param Request $request Swoole 握手请求
     * @param array   $config  websocket 配置段（含 auth 子段）
     *
     * @return array{ok: bool, user_id: string, reason: string}
     *               ok=false 时 reason 会作为 WebSocket 关闭原因传给客户端
     */
    public static function authenticate(Request $request, array $config = []): array
    {
        $auth = $config['auth'] ?? [];
        // 开发模式：不校验 token，user_id 可选（来自 query）
        if (empty($auth['enable'])) {
            return [
                'ok' => true,
                'user_id' => self::resolveUserId($request),
                'reason' => '',
            ];
        }

        $token = self::resolveToken($request);
        if ($token === '') {
            return ['ok' => false, 'user_id' => '', 'reason' => 'missing websocket token'];
        }

        $userId = '';
        if (isset($auth['callback']) && is_callable($auth['callback'])) {
            // 生产推荐：JWT 解析、Session 校验、权限服务等
            $result = call_user_func($auth['callback'], $request, $token);
            if ($result === true) {
                $userId = self::resolveUserId($request);
            } elseif (is_array($result) && !empty($result['user_id'])) {
                $userId = (string) $result['user_id'];
            } else {
                return ['ok' => false, 'user_id' => '', 'reason' => 'websocket auth failed'];
            }
        } else {
            $tokens = $auth['tokens'] ?? [];
            if (!is_array($tokens) || !in_array($token, $tokens, true)) {
                return ['ok' => false, 'user_id' => '', 'reason' => 'invalid websocket token'];
            }
            $userId = self::resolveUserId($request);
        }

        // 禁止「有 token 但无身份」的匿名连接
        if (self::requireUserId($auth) && $userId === '') {
            return ['ok' => false, 'user_id' => '', 'reason' => 'missing user_id after auth'];
        }

        return ['ok' => true, 'user_id' => $userId, 'reason' => ''];
    }

    /**
     * 从握手请求中提取 token（供 callback 或 tokens 列表校验）。
     */
    public static function resolveToken(Request $request): string
    {
        $authorization = (string) ($request->header['authorization'] ?? '');
        if (stripos($authorization, 'bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        return (string) (
            $request->get['token']
            ?? $request->get['access_token']
            ?? $request->header['sec-websocket-protocol']
            ?? ''
        );
    }

    /** 从 query 读取 uid / user_id */
    private static function resolveUserId(Request $request): string
    {
        return trim((string) ($request->get['uid'] ?? $request->get['user_id'] ?? ''));
    }

    /**
     * auth.enable=true 时是否强制非空 user_id。
     *
     * 未显式配置时默认为 true。
     */
    private static function requireUserId(array $auth): bool
    {
        if (array_key_exists('require_user_id', $auth)) {
            return (bool) $auth['require_user_id'];
        }

        return true;
    }
}
