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

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;
use Swoolefy\Script\MainCliScript;

/**
 * 执行命令生成表字段属性： php script.php start Test --c=gen:mysql:schema --db=db --table=tbl_users
 */

class GenerateMysql extends MainCliScript
{
    /**
     * @var string
     */
    const command = "gen:mysql:schema";

    /**
     * @return void
     */
    public function handle()
    {
        $db        = $this->getOption('db');
        $tableName = $this->getOption('table');

        /**
         * @var Mysql $mysqlDb
         */
        $mysqlDb  = Application::getApp()->get($db);
        $result   = $mysqlDb->getFields($tableName);

        $extendType = "";
        $propertyContent = "/**\n";
        foreach ($result as $item) {
            if (str_contains($item['type'], 'int') || str_contains($item['type'], 'tinyint') || str_contains($item['type'], 'smallint') || str_contains($item['type'], 'int(')) {
                $item['type'] = 'int';
            } else if (str_contains($item['type'], 'float') || str_contains($item['type'], 'double') || str_contains($item['type'], 'decimal')) {
                $item['type'] = 'float';
            } else {
                if (str_contains($item['type'], 'json')) {
                    $extendType = 'json';
                }
                $item['type'] = 'string';
            }
            if (!empty($extendType)) {
                $extendType = "";
                $propertyContent .= "* @property {$item['type']} {$item['name']} json类型-{$item['comment']}\n";
            }else {
                $propertyContent .= "* @property {$item['type']} {$item['name']} {$item['comment']}\n";
            }
        }

        $propertyContent .= "*/\n";
        echo "// 生成的表【{$tableName}】的属性\n";
        print_r($propertyContent);
        echo "\n";
    }
}