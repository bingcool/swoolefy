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

use Swoolefy\Script\AbstractKernel;

class RunProcessMetaDto extends AbstractDto
{
    /**
     * @var int
     */
    public $pid = 0;

    /**
     * @var string
     */
    public $command = "";

    /**
     * @var string
     */
    public $pid_file = '';

    /**
     * 总的检查次数
     * @var int
     */
    public $check_total_count = 0;

    /**
     * 检查到pid不存在的次数
     * @var int
     */
    public $check_pid_not_exist_count = 0;

    /**
     * 启动时间戳
     * @var int
     */
    public $start_timestamp = 0;

    /**
     * 启动日期时间
     * @var string
     */
    public $start_date_time = 0;


}