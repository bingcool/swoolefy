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

namespace Swoolefy\Core\Dto;

use Swoolefy\Core\Task\TaskController;
use Swoolefy\Core\Task\TaskService;

class TaskMessageDto extends AbstractDto
{
    /**
     * @var TaskController|TaskService
     */
    public $taskClass;

    /**
     * @var string
     */
    public $taskAction;

    /**
     * @var array
     */
    public $taskData = [];

}