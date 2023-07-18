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

class Exec
{
    /**
     * @var array
     */
    protected $output = [];

    /**
     * @var int
     */
    protected $returnCode;

    /**
     * @var bool
     */
    protected $runFlag = false;

    /**
     * Run the given command.
     *
     * @param  string  $command
     * @param  bool $runAgain
     * @return $this
     */
    public function run(string $command)
    {
        if (!$this->runFlag) {
            exec($command, $output, $returnCode);
            $this->output = $output;
            $this->returnCode = $returnCode;
            $this->runFlag = true;
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getOutput(): array
    {
        return $this->output;
    }

    /**
     * @return int
     */
    public function getReturnCode(): ?int
    {
        return $this->returnCode;
    }
}