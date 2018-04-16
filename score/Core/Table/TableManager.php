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

	use \Swoolefy\Core\SingleTrait;

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
				// 避免重复创建
				if(isset($swoole_tables[$table_name])) {
					continue;
				}
				$table = new \swoole_table($row['size']);
				foreach($row['fields'] as $p => $field) {
					switch(strtolower($field[1])) {
						case 'int':
						case \swoole_table::TYPE_INT:
							$table->column($field[0], \swoole_table::TYPE_INT, (int)$field[2]);
						break;
						case 'string':
						case \swoole_table::TYPE_STRING:
							$table->column($field[0], \swoole_table::TYPE_STRING, (int)$field[2]);
						break;
						case 'float':
						case \swoole_table::TYPE_FLOAT:
							$table->column($field[0], \swoole_table::TYPE_FLOAT, (int)$field[2]);
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
		if(is_string($table) && is_string($key) && !empty($field_value)) {
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
		if(is_string($table) && is_string($key)) {
			return BaseServer::$server->tables[$table]->get($key, $field);
		}
		return false;
	}

	/**
	 * exist 判断是否存在
	 * @param  string $table
	 * @param  string $key
	 * @return boolean
	 */
	public static function exist(string $table, string $key) {
		if(is_string($table) && is_string($key)) {
			return BaseServer::$server->tables[$table]->exist($key);
		}
		return false;
	}

	/**
	 * del 删除某行key
	 * @param  string $table
	 * @param  string $key  
	 * @return boolean
	 */
	public static function del(string $table, string $key) {
		if(is_string($table) && is_string($key)) {
			return BaseServer::$server->tables[$table]->del($key);
		}
		return false;
	}

	/**
	 * incr 原子自增操作
	 * @param  string        $table
	 * @param  string        $key  
	 * @param  string        $field
	 * @param  mixed|integer $incrby
	 * @return mixed              
	 */
	public static function incr(string $table, string $key, string $field, $incrby = 1) {
		if(is_string($table) && is_string($key) && is_string($field)) {
			return BaseServer::$server->tables[$table]->incr($key, $field, $incrby);
		}
		return false;
	}

	/**
	 * decr 原子自减操作
	 * @param  string        $table
	 * @param  string        $key  
	 * @param  string        $field
	 * @param  mixed|integer $incrby
	 * @return mixed              
	 */
	public static function decr(string $table, string $key, string $field, $incrby = 1) {
		if(is_string($table) && is_string($key) && is_string($field)) {
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
	 * @return boolean
	 */
	public static function isExistTable(string $table = null) {
		if(isset(BaseServer::$server->tables)) {
			if($table) {
				if(isset(BaseServer::$server->tables[$table])) {
					return true;
				}
				return false;
			}
			throw new \Exception("miss table argument", 1);
		}
	}
}