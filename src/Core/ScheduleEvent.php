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

namespace Swoolefy\Core;

use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Worker\Cron\CronForkProcess;

class ScheduleEvent extends AbstractDto
{
    public $command;

    public $fork_type = CronForkProcess::FORK_TYPE_PROC_OPEN;

    public $cron_expression;

    public $argv = [];

    public $description = '';

    public function command(string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @param int|string $cronExpression
     * @return $this
     */
    public function cron($cronExpression): self
    {
        $this->cron_expression = $cronExpression;
        return $this;
    }

    public function forkType($forkType): self
    {
        $this->fork_type = $forkType;
        return $this;
    }

    public function addArgs(string $name, $value):self
    {
        $this->argv[$name] = $value;
        return $this;
    }

    public function description(string $description = ''): self
    {
        $this->description = $description;
        return $this;
    }
}