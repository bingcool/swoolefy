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
 * CLI → Worker Master 控制请求（值对象，JSON 映射）。
 *
 * action 是顶层指令（status/stop/restart/send），与 WORKER_CLI_* 常量一一对应；
 * target 是目标 process_name；op/message 只对 send 有意义，op 内层动作，
 * message 是透传给目标进程的原始内容。
 */
final class ControlRequest
{
    /** 协议版本，升级不兼容字段时递增 */
    public const VERSION = 1;

    /** 顶层允许的 action */
    public const ACTIONS = [
        WORKER_CLI_STATUS,
        WORKER_CLI_STOP,
        WORKER_CLI_RESTART,
        WORKER_CLI_SEND_MSG,
    ];

    public function __construct(
        public readonly string $action,
        public readonly string $target = '',
        public readonly string $op = '',
        public readonly string $message = '',
        public readonly string $nonce = '',
        public readonly int $version = self::VERSION
    ) {
    }

    /**
     * @throws WorkerException JSON 非法、版本不支持或 action 未知
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new WorkerException('控制请求不是合法 JSON: ' . json_last_error_msg());
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     * @throws WorkerException
     */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['v'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new WorkerException("控制协议版本不支持: {$version}");
        }

        $action = is_string($data['action'] ?? null) ? $data['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            throw new WorkerException("未知控制动作: {$action}");
        }

        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return new self(
            $action,
            is_string($data['target'] ?? null) ? $data['target'] : '',
            is_string($params['op'] ?? null) ? $params['op'] : '',
            is_string($params['msg'] ?? null) ? $params['msg'] : '',
            is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            $version
        );
    }

    /**
     * @return array{v:int,action:string,target:string,params:array{op:string,msg:string},nonce:string}
     */
    public function toArray(): array
    {
        return [
            'v' => $this->version,
            'action' => $this->action,
            'target' => $this->target,
            'params' => [
                'op' => $this->op,
                'msg' => $this->message,
            ],
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
