<?php

namespace Test\Scripts;

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;
use Swoolefy\Script\MainCliScript;

/**
 * 执行命令生成表属性： php script.php start Test --c=gen:mysql:schema --db=db --table=tbl_users
 */

/**
 * @property int user_id 用户id
 * @property string user_name 用户名称
 * @property int sex 用户性别，0-男，1-女
 * @property string birthday 出生年月
 * @property string phone 手机号
 * @property string extand_json 扩展数据
 * @property string gmt_create 创建时间
 * @property string gmt_modify 更新时间
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
        $db = getenv('db');
        $tableName = getenv('table');
        /**
         * @var Mysql $mysql
         */
        $mysql = Application::getApp()->get($db);
        $result = $mysql->getFields($tableName);

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