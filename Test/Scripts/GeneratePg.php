<?php

namespace Test\Scripts;

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;
use Swoolefy\Script\MainCliScript;

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

class GeneratePg extends GenerateMysql
{
    /**
     * @var string
     */
    const command = "gen:pgsql:schema";
}