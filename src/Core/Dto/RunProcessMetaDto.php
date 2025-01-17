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
    public $pid = 0;

    public $command = "";

    public $pid_file = '';

    // 总的检查次数
    public $check_total_count = 0;

    // 检查到pid不存在的次数
    public $check_pid_not_exist_count = 0;

    public $start_time = 0;


}