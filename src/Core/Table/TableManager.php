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

namespace Swoolefy\Core\Table;

use Swoolefy\Core\BaseServer;

class TableManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * createTable
     * @param array $tables
     * @return boolean
     */
    public static function createTable(array $tables = [])
    {
        $swooleTables = [];
        if (isset(BaseServer::$server->tables) && is_array(BaseServer::$server->tables)) {
            $swooleTables = BaseServer::$server->tables;
        }
        if (is_array($tables) && !empty($tables)) {
            foreach ($tables as $tableName => $row) {
                if (isset($swooleTables[$tableName])) {
                    continue;
                }
                $table = new \Swoole\Table($row['size']);
                foreach ($row['fields'] as $field) {
                    switch (strtolower($field[1])) {
                        case 'int':
                        case 'integer':
                        case \Swoole\Table::TYPE_INT:
                            $table->column($field[0], \Swoole\Table::TYPE_INT, (int)$field[2]);
                            break;
                        case 'string':
                        case \Swoole\Table::TYPE_STRING:
                            $table->column($field[0], \Swoole\Table::TYPE_STRING, (int)$field[2]);
                            break;
                        case 'float':
                        case 'double':
                        case \Swoole\Table::TYPE_FLOAT:
                            $table->column($field[0], \Swoole\Table::TYPE_FLOAT, (int)$field[2]);
                            break;
                    }
                }
                if ($table->create()) {
                    $swooleTables[$tableName] = $table;
                }
            }
            // todo 8.2+
            BaseServer::$server->tables = $swooleTables;
            unset($swooleTables);
            return true;
        }

        return false;
    }

    /**
     * set
     * @param string $table
     * @param string $key
     * @param array $field_value
     */
    public static function set(string $table, string $key, array $field_value = [])
    {
        if (!empty($field_value)) {
            self::getTable($table)->set($key, $field_value);
        }
    }

    /**
     * get
     * @param string $table
     * @param string $key
     * @param string $field
     * @return mixed
     */
    public static function get(string $table, string $key, string $field = null)
    {
        return self::getTable($table)->get($key, $field);
    }

    /**
     * exist 判断是否存在
     * @param string $table
     * @param string $key
     * @return boolean
     */
    public static function exist(string $table, string $key)
    {
        return self::getTable($table)->exist($key);
    }

    /**
     * del 删除某行key
     * @param string $table
     * @param string $key
     * @return boolean
     */
    public static function del(string $table, string $key)
    {
        return self::getTable($table)->del($key);

    }

    /**
     * incr 原子自增操作
     * @param string $table
     * @param string $key
     * @param string $field
     * @param mixed|int $incrBy
     * @return mixed
     */
    public static function incr(string $table, string $key, string $field, int $incrBy = 1)
    {
        if (is_int($incrBy) || is_float($incrBy)) {
            return self::getTable($table)->incr($key, $field, $incrBy);
        }
        return false;
    }

    /**
     * decr 原子自减操作
     * @param string $table
     * @param string $key
     * @param string $field
     * @param mixed|int $incrBy
     * @return mixed
     */
    public static function decr(string $table, string $key, string $field, int $incrBy = 1)
    {
        if (is_int($incrBy) || is_float($incrBy)) {
            return self::getTable($table)->decr($key, $field, $incrBy);
        }
        return false;
    }

    /**
     * getTables 获取已创建的内存表的名称
     * @return array
     */
    public static function getTablesName()
    {
        if (isset(BaseServer::$server->tables)) {
            return array_keys(BaseServer::$server->tables);
        }
        return null;
    }

    /**
     * getTable 获取已创建的table实例对象
     * @param string|null $table
     * @return \Swoole\Table|array
     * @throws Exception
     */
    public static function getTable(string $table)
    {
        if (isset(BaseServer::$server->tables)) {
            if (!isset(BaseServer::$server->tables[$table])) {
                throw new \Exception("Not exist Table={$table}");
            }
            return BaseServer::$server->tables[$table];
        }
    }

    /**
     * isExistTable 判断是否已创建内存表
     * @param string|null $table
     * @return boolean
     * @throws mixed
     */
    public static function isExistTable(string $table)
    {
        if (isset(BaseServer::$server->tables)) {
            if ($table) {
                if (isset(BaseServer::$server->tables[$table])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * count 计算表的存在条目数
     * @param string $table
     * @return int
     */
    public static function count(string $table = null)
    {
        $swooleTable = self::getTable($table);
        if ($swooleTable) {
            $count = $swooleTable->count();
        }
        if (is_numeric($count)) {
            return $count;
        }
        return null;

    }

    /**
     * 获取已设置的key
     * @param string $table
     * @return array
     */
    public static function getTableKeys(string $table)
    {
        $keys = [];
        $swooleTable = self::getTable($table);
        if (is_object($swooleTable) && $swooleTable instanceof \Swoole\Table) {
            foreach ($swooleTable as $key => $item) {
                array_push($keys, $key);
            }
        }
        return $keys;
    }

    /**
     * 获取table的key映射的每一行数据rowValue
     * @param string $table
     * @return array
     */
    public static function getKeyMapRowValue(string $table)
    {
        $tableRows = [];
        $swooleTable = self::getTable($table);
        if (is_object($swooleTable) && $swooleTable instanceof \Swoole\Table) {
            foreach ($swooleTable as $key => $item) {
                $tableRows[$key] = $item;
            }
        }
        return $tableRows;
    }
}