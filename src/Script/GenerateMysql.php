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
    public function generate()
    {
        $db        = getenv('db');
        $tableName = getenv('table');

        /**
         * @var Mysql $mysqlDb
         */
        $mysqlDb  = Application::getApp()->get($db);
        $result = $mysqlDb->getFields($tableName);

        $propertyContent = "/**\n";
        foreach ($result as $item) {
            if (strpos($item['type'],'int(') !== false || strpos($item['type'],'int') !== false ) {
                $item['type'] = 'int';
            }else if (strpos($item['type'],'float(') !== false  || strpos($item['type'],'float') !== false ) {
                $item['type'] = 'float';
            }else {
                $item['type'] = 'string';
            }
            $propertyContent .= "* @property {$item['type']} {$item['name']} {$item['comment']}\n";
        }

        $propertyContent .= "*/\n";
        echo "生成的表【{$tableName}】的属性如下：\n";
        print_r($propertyContent);
        echo "\n";
    }
}