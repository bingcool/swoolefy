<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db;

use PDO;

class Mysql extends PDOConnection {

    /**
     * 解析pdo连接的dsn信息
     * @return string
     */
    public function parseDsn(): string
    {
        $config = $this->config;
        if (!empty($config['socket'])) {
            $dsn = 'mysql:unix_socket=' . $config['socket'];
        } elseif (!empty($config['hostport'])) {
            $dsn = 'mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'];
        } else {
            $dsn = 'mysql:host=' . $config['hostname'];
        }
        $dsn .= ';dbname=' . $config['database'];

        if (!empty($config['charset'])) {
            $dsn .= ';charset=' . $config['charset'];
        }

        return $dsn;
    }

    /**
     * 取得数据表的字段信息
     * @param  string $tableName
     * @return array
     */
    public function getFields(string $tableName): array
    {
        $sourceTableName = $tableName;
        if(!isset($this->tableFields[$tableName]) && empty($this->tableFields[$tableName]) || isset($this->objExpireTime)) {

            [$tableName] = explode(' ', $tableName);

            if (false === strpos($tableName, '`')) {
                if (strpos($tableName, '.')) {
                    $tableName = str_replace('.', '`.`', $tableName);
                }
                $tableName = '`' . $tableName . '`';
            }

            $sql    = 'SHOW FULL COLUMNS FROM ' . $tableName;
            $pdo    = $this->PDOStatementHandle($sql);
            $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
            $info   = [];

            if(!empty($result)) {
                foreach ($result as $key => $val) {
                    $val = array_change_key_case($val);

                    $info[$val['field']] = [
                        'name'    => $val['field'],
                        'type'    => $val['type'],
                        'notnull' => 'NO' == $val['null'],
                        'default' => $val['default'],
                        'primary' => strtolower($val['key']) == 'pri',
                        'autoinc' => strtolower($val['extra']) == 'auto_increment',
                        'comment' => $val['comment'],
                    ];
                }
            }
            $fieldResult = $this->fieldCase($info);
            $this->tableFields[$sourceTableName] = $fieldResult;
        }

        return  $this->tableFields[$sourceTableName];
    }

    /**
     * 取得数据库的表信息
     * @access public
     * @param  string $dbName
     * @return array
     */
    public function getTables(string $dbName = ''): array
    {
        $sql    = !empty($dbName) ? 'SHOW TABLES FROM ' . $dbName : 'SHOW TABLES ';
        $pdo    = $this->PDOStatementHandle($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];

        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }

        return $info;
    }

    /**
     * 启动XA事务
     * @param string $xid XA事务id
     * @return void
     * @throws \Exception
     */
    public function startTransXa(string $xid)
    {
        $this->initConnect(true);
        $this->PDOInstance->exec("XA START '$xid'");
    }

    /**
     * 预编译XA事务
     * @param string $xid XA事务id
     * @return void
     * @throws \Exception
     */
    public function prepareXa(string $xid)
    {
        $this->initConnect();
        $this->PDOInstance->exec("XA END '$xid'");
        $this->PDOInstance->exec("XA PREPARE '$xid'");
    }

    /**
     * 提交XA事务
     * @param string $xid XA事务id
     * @return void
     * @throws \Exception
     */
    public function commitXa(string $xid)
    {
        $this->initConnect();
        $this->PDOInstance->exec("XA COMMIT '$xid'");
    }

    /**
     * 回滚XA事务
     * @param string $xid XA事务id
     * @return void
     * @throws \Exception
     */
    public function rollbackXa(string $xid)
    {
        $this->initConnect();
        $this->PDOInstance->exec("XA ROLLBACK '$xid'");
    }

}