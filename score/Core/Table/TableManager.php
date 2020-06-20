<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Table;

use Swoole\Table;
use Swoolefy\Core\BaseServer;

class TableManager {

	use \Swoolefy\Core\SingletonTrait;

    /**
     * createTable 
     * @param  array  $tables
     * @return boolean
     */
	public static function createTable(array $tables = []) {
		if(isset(BaseServer::$server->tables) && is_array(BaseServer::$server->tables)) {
			$swoole_tables = BaseServer::$server->tables;
		}else {
			$swoole_tables = [];
		}
		if(is_array($tables) && !empty($tables)) {
			foreach($tables as $table_name => $row) {
				if(isset($swoole_tables[$table_name])) {
					continue;
				}
				$table = new \Swoole\Table($row['size']);
				foreach($row['fields'] as $p => $field) {
					switch(strtolower($field[1])) {
						case 'int':
						case \Swoole\Table::TYPE_INT:
							$table->column($field[0], \Swoole\Table::TYPE_INT, (int)$field[2]);
						break;
						case 'string':
						case \Swoole\Table::TYPE_STRING:
							$table->column($field[0], \Swoole\Table::TYPE_STRING, (int)$field[2]);
						break;
						case 'float':
						case \Swoole\Table::TYPE_FLOAT:
							$table->column($field[0], \Swoole\Table::TYPE_FLOAT, (int)$field[2]);
						break;
					}
				}
				if($table->create()) {
					$swoole_tables[$table_name] = $table;
				}				
			}

			BaseServer::$server->tables = $swoole_tables;
			unset($swoole_tables);
			return true;
		}

		return false;
	}

	/**
	 * set 
	 * @param string $table      
	 * @param string $key        
	 * @param array  $field_value
	 */
	public static function set(string $table, string $key, array $field_value = []) {
		if(!empty($field_value)) {
			BaseServer::$server->tables[$table]->set($key, $field_value);
		}	
	}

	/**
	 * get 
	 * @param  string $table
	 * @param  string $key  
	 * @param  string $field
	 * @return mixed       
	 */
	public static function get(string $table, string $key, $field = null) {
	    return BaseServer::$server->tables[$table]->get($key, $field);
	}

	/**
	 * exist 判断是否存在
	 * @param  string $table
	 * @param  string $key
	 * @return boolean
	 */
	public static function exist(string $table, string $key) {
	    return BaseServer::$server->tables[$table]->exist($key);
	}

	/**
	 * del 删除某行key
	 * @param  string $table
	 * @param  string $key  
	 * @return boolean
	 */
	public static function del(string $table, string $key) {
	    return BaseServer::$server->tables[$table]->del($key);

	}

	/**
	 * incr 原子自增操作
	 * @param  string        $table
	 * @param  string        $key  
	 * @param  string        $field
	 * @param  mixed|int $incrby
	 * @return mixed              
	 */
	public static function incr(string $table, string $key, string $field, $incrby = 1) {
		if(is_int($incrby) || is_float($incrby)) {
			return BaseServer::$server->tables[$table]->incr($key, $field, $incrby);
		}
		return false;
	}

	/**
	 * decr 原子自减操作
	 * @param  string        $table
	 * @param  string        $key  
	 * @param  string        $field
	 * @param  mixed|int $incrby
	 * @return mixed              
	 */
	public static function decr(string $table, string $key, string $field, $incrby = 1) {
		if(is_int($incrby) || is_float($incrby)) {
			return BaseServer::$server->tables[$table]->decr($key, $field, $incrby);
		}
		return false;
	}

	/**
	 * getTables 获取已创建的内存表的名称
	 * @return mixed
	 */
	public static function getTablesName() {
		if(isset(BaseServer::$server->tables)) {
			return array_keys(BaseServer::$server->tables);
		}
		return null;
	}

	/**
	 * getTable 获取已创建的table实例对象
	 * @param  string|null $table
	 * @return object
	 */
	public static function getTable(string $table = null) {
		if(isset(BaseServer::$server->tables)) {
			if($table) {
				return BaseServer::$server->tables[$table];
			}
			return null;
		}

		return null;
	}

	/**
	 * isExistTable 判断是否已创建内存表
	 * @param  string|null $table
     * @throws mixed
	 * @return boolean
	 */
	public static function isExistTable(string $table) {
		if(isset(BaseServer::$server->tables)) {
			if($table) {
				if(isset(BaseServer::$server->tables[$table])) {
					return true;
				}
				return false;
			}
			return false;
		}
	}

	/**
	 * count 计算表的存在条目数
     * @param string $table
	 * @return int
	 */
	public static function count(string $table = null) {
		$swoole_table = self::getTable($table);
		if($swoole_table) {
			$count = $swoole_table->count();
		}
		if(is_numeric($count)) {
			return $count;
		}
		return null; 

	}

    /**
     * 获取已设置的key
     * @param string $table
     * @return array
     */
	public static function getTableKeys(string $table) {
        $keys = [];
        $swoole_table = self::getTable($table);
        if(is_object($swoole_table) && $swoole_table instanceof \Swoole\Table) {
            foreach ($swoole_table as $key => $item) {
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
    public static function getKeyMapRowValue(string $table) {
        $table_rows = [];
        $swoole_table = self::getTable($table);
        if(is_object($swoole_table) && $swoole_table instanceof \Swoole\Table) {
            foreach ($swoole_table as $key => $item) {
                $table_rows[$key] = $item;
            }
        }
        return $table_rows;
    }


}