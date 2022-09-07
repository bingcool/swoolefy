<?php
/**
 * +----------------------------------------------------------------------
 * | Daemon and Cli model about php process worker
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Workerfy\Dto;

class PipeMsgDto extends AbstractDto
{
    /**
     * @var string
     */
    public $action;

    /**
     * @var string
     */
    public $targetHandler;

    /**
     * @var string
     */
    public $message;
}