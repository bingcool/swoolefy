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

namespace Swoolefy\Worker\Dto;

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