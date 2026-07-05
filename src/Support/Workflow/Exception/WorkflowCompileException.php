<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Exception;

/** 工作流编译期校验失败（环、未知节点、边冲突等）。 */
final class WorkflowCompileException extends WorkflowException
{
}
