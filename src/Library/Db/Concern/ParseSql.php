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

namespace Swoolefy\Library\Db\Concern;

use DateTime;

trait ParseSql {

    /**
     * @param array $allowFields
     * @return array
     */
    protected function parseInsertSql(array $allowFields) {
        $fields = $columns = $bindParams = [];
        foreach($allowFields as $field) {
            if(isset($this->data[$field])) {
                $fields[] = $field;
                $column = ':'.$field;
                $columns[] = $column;
                $bindParams[$column] = $this->data[$field];
            }else {
                unset($this->data[$field]);
            }
        }
        $fields = implode(',', $fields);
        $columns = implode(',', $columns);
        $sql = "INSERT INTO {$this->table} ({$fields}) VALUES ({$columns}) ";
        return [$sql, $bindParams];
    }

    /**
     * @return array
     */
    protected function parseFindSqlByPk() {
        $pk = $this->getPk();
        $sql = "SELECT * FROM {$this->table} WHERE {$pk}=:pk";
        $bindParams = [
            ':pk'=>$this->getPkValue() ?? 0
        ];
        return [$sql, $bindParams];
    }

    /**
     * @param array $diffData
     * @param array $allowFields
     * @return array
     */
    protected function parseUpdateSql(array $diffData, array $allowFields) {
        $setValues = $bindParams = [];
        $pk = $this->getPk();
        foreach($allowFields as $field) {
            if(isset($diffData[$field])) {
                $column = ':'.$field;
                $setValues[] = $field.'='.$column;
                $bindParams[$column] = $diffData[$field];
            }
        }
        $setValueStr = implode(',', $setValues);
        $sql = "UPDATE {$this->table} SET {$setValueStr} WHERE {$pk}=:pk";
        $bindParams[':pk'] = $this->getPkValue() ?? 0;
        return [$sql, $bindParams];
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return ParseSql|null
     */
    public function findOne(string $sql, array $bindParams = []) {
        $attributes = $this->getConnection()->createCommand($sql)->findOne($bindParams);
        if($attributes) {
            foreach($attributes as $field => $value) {
                $this->data[$field] = $value;
            }
            // 记录源数据
            $this->origin = $this->data;
            $this->exists(true);
            $result = $this;
        }
        return $result ?? null;
    }

    /**
     * @param string $where
     * @param array $bindParams
     * @return ParseSql
     */
    public function findWhere(string $where, array $bindParams = []) {
        $sql = "SELECT * FROM {$this->table} WHERE {$where}";
        return $this->findOne($sql, $bindParams);
    }

    /**
     * @return $this
     */
    public static function model() {
        $model = new static();
        return $model;
    }

}