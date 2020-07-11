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

class Pgsql extends PDOConnection {

    /**
     * 默认PDO连接参数
     * @var array
     */
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    /**
     * 解析pdo连接的dsn信息
     * @param  array $config 连接信息
     * @return string
     */
    protected function parseDsn(): string
    {
        $dsn = 'pgsql:dbname=' . $this->config['database'] . ';host=' . $this->config['hostname'];

        if (!empty($config['hostport'])) {
            $dsn .= ';port=' . $config['hostport'];
        }

        return $dsn;
    }

    /**
     * 取得数据表的字段信息
     * table_msg这个函数需要用户自定义,执行本目录下的pgsql.sql代码段创建该函数
     * @param  string $tableName
     * @return array
     */
    public function getFields(string $tableName): array
    {
        $sourceTableName = $tableName;
        if(!isset($this->tableFields[$tableName])) {
            [$tableName] = explode(' ', $tableName);
            $sql         = 'select fields_name as "field",fields_type as "type",fields_not_null as "null",fields_key_name as "key",fields_default as "default",fields_default as "extra" from table_msg(\'' . $tableName . '\');';

            $pdo    = $this->PDOStatementHandle($sql);
            $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
            $info   = [];

            if (!empty($result)) {
                foreach ($result as $key => $val) {
                    $val = array_change_key_case($val);

                    $info[$val['field']] = [
                        'name'    => $val['field'],
                        'type'    => $val['type'],
                        'notnull' => (bool) ('' !== $val['null']),
                        'default' => $val['default'],
                        'primary' => !empty($val['key']),
                        'autoinc' => (0 === strpos($val['extra'], 'nextval(')),
                    ];
                }
            }

            $fieldResult = $this->fieldCase($info);
            $this->tableFields[$sourceTableName] = $fieldResult;
        }

        return $this->tableFields[$sourceTableName];

    }

    /**
     * 取得数据库的表信息
     * @param  string $dbName
     * @return array
     */
    public function getTables(string $dbName = ''): array
    {
        $sql    = "select tablename as Tables_in_test from pg_tables where  schemaname ='public'";
        $pdo    = $this->PDOStatementHandle($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];

        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }

        return $info;
    }

    protected function supportSavepoint(): bool
    {
        return true;
    }
}
