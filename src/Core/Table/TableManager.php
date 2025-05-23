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
use Swoolefy\Exception\SystemException;

class TableManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * createTable
     * @param array $tables
     * @return bool
     */
    public static function createTable(array $tables = [])
    {
        if (!$tables) {
            return false;
        }

        foreach ($tables as $tableName => $row) {
            if (isset(BaseServer::$tableMemory[$tableName])) {
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
                BaseServer::$tableMemory[$tableName] = $table;
            }
        }

        return true;
    }

    /**
     * set
     * @param string $table
     * @param string $key
     * @param array $fieldValue
     * @return void
     */
    public static function set(string $table, string $key, array $fieldValue = [])
    {
        if (!empty($fieldValue)) {
            self::getTable($table)->set($key, $fieldValue);
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
     * @return bool
     */
    public static function exist(string $table, string $key): bool
    {
        return self::getTable($table)->exist($key);
    }

    /**
     * del 删除某行key
     * @param string $table
     * @param string $key
     * @return bool
     */
    public static function del(string $table, string $key): bool
    {
        return self::getTable($table)->del($key);

    }

    /**
     * incr 原子自增操作
     * @param string $table
     * @param string $key
     * @param string $field
     * @param int $incrBy
     * @return int
     */
    public static function incr(string $table, string $key, string $field, int $incrBy = 1): int
    {
        return self::getTable($table)->incr($key, $field, $incrBy);
    }

    /**
     * decr 原子自减操作
     * @param string $table
     * @param string $key
     * @param string $field
     * @param int $incrBy
     * @return int
     */
    public static function decr(string $table, string $key, string $field, int $incrBy = 1): int
    {
        return self::getTable($table)->decr($key, $field, $incrBy);
    }

    /**
     * getTables 获取已创建的内存表的名称
     * @return array
     */
    public static function getTableNames(): array
    {
        return array_keys(BaseServer::$tableMemory);
    }

    /**
     * getTable 获取已创建的table实例对象
     * @param string|null $table
     * @return \Swoole\Table
     * @throws SystemException
     */
    public static function getTable(string $table): \Swoole\Table
    {
        if (!isset(BaseServer::$tableMemory[$table])) {
            throw new SystemException("Not exist Memory Table={$table}");
        }
        return BaseServer::$tableMemory[$table];
    }

    /**
     * isExistTable 判断是否已创建内存表
     * @param string|null $table
     * @return bool
     */
    public static function isExistTable(string $table): bool
    {
        if (isset(BaseServer::$tableMemory[$table])) {
            return true;
        }
        return false;
    }

    /**
     * count 计算表的存在条目数
     * @param string $table
     * @return int
     */
    public static function count(string $table = null): int
    {
        $count = 0;
        $swooleTable = self::getTable($table);
        if ($swooleTable) {
            $count = $swooleTable->count();
        }

        if (is_numeric($count)) {
            return $count;
        }
        return $count;

    }

    /**
     * 获取已设置的key
     * @param string $table
     * @return array
     */
    public static function getTableKeys(string $table): array
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
    public static function getKeyMapRowValue(string $table): array
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

    /**
     * get all table_name instance
     * @return array
     */
    public function getAllTableName(): array
    {
        $tableNames = array_keys(BaseServer::$tableMemory);
        return $tableNames;
    }

    /**
     * getAllTableKeyMapRowValue
     * @return array
     */
    public function getAllTableKeyMapRowValue(): array
    {
        $tableInfos = [];
        $tableNames = $this->getAllTableName();
        foreach ($tableNames as $tableName) {
            $tableInfos[$tableName] = $this->getKeyMapRowValue($tableName);
        }
        return $tableInfos;
    }
}