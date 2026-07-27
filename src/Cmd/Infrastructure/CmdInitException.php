<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * 命令初始化异常。
 *
 * 当 BaseCmd::initialize() 阶段发生校验失败（如缺少常量、环境变量无效等），
 * 抛出此异常替代 exit(0)，使调用方能通过 try-catch 统一处理。
 *
 * 在 BaseCmd::initialize() 中被捕获后设置 $initError，
 * 各 Command::execute() 开头通过 $this->hasInitError() 检查并提前返回 Command::FAILURE。
 */
class CmdInitException extends \RuntimeException
{
}
