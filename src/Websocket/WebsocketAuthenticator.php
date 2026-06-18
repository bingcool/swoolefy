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

class WebsocketAuthenticator
{
    /**
     * 鉴权配置示例：
     * 'websocket' => [
     *   'auth' => [
     *     'enable' => true,
     *     'tokens' => ['dev-token'],
     *     'callback' => fn(Request $request, string $token): array|bool => ['user_id' => '10001'],
     *   ],
     * ]
     */
    public static function authenticate(Request $request, array $config = []): array
    {
        $auth = $config['auth'] ?? [];
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

        if (isset($auth['callback']) && is_callable($auth['callback'])) {
            // 生产建议使用 callback：可校验 JWT/session，并返回 user_id 完成连接身份绑定。
            $result = call_user_func($auth['callback'], $request, $token);
            if ($result === true) {
                return ['ok' => true, 'user_id' => self::resolveUserId($request), 'reason' => ''];
            }
            if (is_array($result) && !empty($result['user_id'])) {
                return ['ok' => true, 'user_id' => (string) $result['user_id'], 'reason' => ''];
            }
            return ['ok' => false, 'user_id' => '', 'reason' => 'websocket auth failed'];
        }

        $tokens = $auth['tokens'] ?? [];
        if (is_array($tokens) && in_array($token, $tokens, true)) {
            return ['ok' => true, 'user_id' => self::resolveUserId($request), 'reason' => ''];
        }

        return ['ok' => false, 'user_id' => '', 'reason' => 'invalid websocket token'];
    }

    public static function resolveToken(Request $request): string
    {
        // token 提取优先级：Authorization Bearer > query token/access_token > Sec-WebSocket-Protocol。
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

    private static function resolveUserId(Request $request): string
    {
        return (string) ($request->get['uid'] ?? $request->get['user_id'] ?? '');
    }
}
