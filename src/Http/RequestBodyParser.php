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

namespace Swoolefy\Http;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Exception\InvalidJsonException;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;

/**
 * 按 Content-Type 安全解析 JSON 请求体，避免对二进制/表单 body 误 json_decode。
 */
final class RequestBodyParser
{
    /**
     * @return array<string, mixed>
     * @throws InvalidJsonException
     */
    public static function parseJsonPayload(?string $contentType, string|false $rawBody, string $method): array
    {
        if (!self::shouldParseJson($contentType, $rawBody, $method)) {
            return [];
        }

        // 空 body：保持现有约定，视为无 JSON 字段
        if (!is_string($rawBody) || $rawBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $requestId = '';
            if (SwooleContext::has(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID)) {
                $requestId = (string) SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);
            }
            // 不记录完整 body，仅 reason + request_id
            throw InvalidJsonException::fromJsonException($e, $requestId);
        }

        // 合法 JSON null：与历史 is_array 分支一致，当作空输入
        if ($decoded === null) {
            return [];
        }

        // 标量 JSON（字符串/数字等）不作为表单字段合并
        return is_array($decoded) ? $decoded : [];
    }

    private static function shouldParseJson(?string $contentType, string|false $rawBody, string $method): bool
    {
        if (self::shouldSkip($contentType)) {
            return false;
        }

        $mime = strtolower(trim(explode(';', (string) $contentType)[0]));
        if ($mime === 'application/json' || str_contains($mime, '+json')) {
            return true;
        }

        // 显式非 JSON 类型不再尝试解析
        if ($mime !== '') {
            return false;
        }

        // 无 Content-Type 时，仅兼容带 body 的写操作且内容形如 JSON
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if (!is_string($rawBody) || $rawBody === '') {
            return false;
        }

        $first = substr(ltrim($rawBody), 0, 1);

        return $first === '{' || $first === '[';
    }

    private static function shouldSkip(?string $contentType): bool
    {
        $contentType = strtolower((string) $contentType);

        if ($contentType === '') {
            return false;
        }

        return str_contains($contentType, 'multipart/form-data')
            || str_contains($contentType, 'application/octet-stream');
    }
}
