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

use Swoolefy\Core\ResponseFormatter;

class WebsocketResponse
{
    public const TYPE_RESPONSE = 'response';
    public const TYPE_ERROR = 'error';
    public const TYPE_EVENT = 'event';
    public const TYPE_PONG = 'pong';

    public static function success(string $requestId = '', $data = [], string $event = ''): string
    {
        return self::encode(self::TYPE_RESPONSE, 0, 'ok', $data, $requestId, $event);
    }

    public static function error(string $msg, int $code = -1, string $requestId = '', string $event = ''): string
    {
        return self::encode(self::TYPE_ERROR, $code, $msg, [], $requestId, $event);
    }

    public static function event(string $event, $data = [], string $requestId = ''): string
    {
        return self::encode(self::TYPE_EVENT, 0, 'ok', $data, $requestId, $event);
    }

    public static function pong(string $requestId = ''): string
    {
        return self::encode(self::TYPE_PONG, 0, 'pong', [], $requestId);
    }

    private static function encode(string $type, int $code, string $msg, $data = [], string $requestId = '', string $event = ''): string
    {
        $response = ResponseFormatter::formatDataArray($code, $msg, $data);
        $payload = [
            'type' => $type,
            'request_id' => $requestId,
            'event' => $event,
            'code' => $response['code'] ?? $code,
            'msg' => $response['msg'] ?? $msg,
            'trace_id' => $response['trace_id'] ?? '',
            'data' => $response['data'] ?? [],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
