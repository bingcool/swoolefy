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

namespace Swoolefy\Script;

/**
 * 执行命令生成表字段属性： php script.php start Test --c=gen:pgsql:schema --db=db --table=tbl_users
 */

class GeneratePg extends GenerateMysql
{
    /**
     * @var string
     */
    const command = "gen:pgsql:schema";
}