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

namespace Swoolefy\Worker\Dto;

use Swoolefy\Exception\WorkerException;

/**
 * 控制通道响应信封。一个请求恰好一帧响应。
 *
 * message 是给人看的一行文案；data 放结构化/多行内容（status 表格文本）；
 * nonce 原样回显请求值，只用于日志串联。
 */
final class ControlResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly string $data = '',
        public readonly string $nonce = ''
    ) {
    }

    public static function success(string $message = '', string $data = '', string $nonce = ''): self
    {
        return new self(true, $message, $data, $nonce);
    }

    public static function failure(string $message, string $nonce = ''): self
    {
        return new self(false, $message, '', $nonce);
    }

    /**
     * @throws WorkerException JSON 非法或字段类型不符
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new WorkerException('控制响应不是合法 JSON: ' . json_last_error_msg());
        }

        return new self(
            (bool) ($decoded['ok'] ?? false),
            is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
            is_string($decoded['data'] ?? null) ? $decoded['data'] : '',
            is_string($decoded['nonce'] ?? null) ? $decoded['nonce'] : ''
        );
    }

    /**
     * @return array{ok:bool,message:string,data:string,nonce:string}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'data' => $this->data,
            'nonce' => $this->nonce,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
