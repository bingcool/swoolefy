<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

/**
 * 停止操作状态枚举。
 *
 * 使用 backed enum 替代字符串常量，避免拼写错误（如 'sucess'）。
 * 每个 case 附带中文标签，用于 CLI 输出和日志。
 */
enum StopStatus: string
{
    /** 服务已成功停止 */
    case SUCCESS = 'success';

    /** 服务在调用停止前已经处于停止状态 */
    case ALREADY_STOPPED = 'already_stopped';

    /** 停止操作超时，已强制杀死残留进程 */
    case TIMEOUT = 'timeout';

    /** PID 文件不存在，可能服务从未启动 */
    case PID_NOT_FOUND = 'pid_not_found';

    /** PID 文件内容无效（非数字或为 0） */
    case INVALID_PID = 'invalid_pid';

    /**
     * 获取状态的中文标签，用于 CLI 输出。
     */
    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => '停止成功',
            self::ALREADY_STOPPED => '服务已停止',
            self::TIMEOUT => '停止超时',
            self::PID_NOT_FOUND => 'PID文件不存在',
            self::INVALID_PID => 'PID无效',
        };
    }
}
