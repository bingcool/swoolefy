<?php
namespace Swoolefy\Core\Mongodb;

class MongodbCollection {
    /**
     * $collectionInstance  collection实例
     * @var null
     */
    public $collectionInstance = null;
    
    /**
     * $filter where的过滤条件
     * @var array
     */
    public $filter = [];

    /**
     * $options 其他选项
     * @var array
     */
    public $options = [];
    
    public function __construct($collection) {
        $this->collectionInstance = MongodbModel::$databaseObject->$collection;
    }

    /**
     * clear 由于是单例模式，需要每次实例collection时，清空条件
     * @return void
     */
    public function clear() {
        $this->filter = $this->options = [];
        return $this;
    }
    
    /**
     * bulkWrite 批量执行操作命令数据
     * @param   array   $insertData
     * @return    void
     */
    public  function bulkWrite($insertData) {
        return $this->collectionInstance->bulkWrite($insertData);
    }

    /**
     * where 条件设置
     * @param   array   $filter
     * @return    $this
     */
    public function where($filter) {
        $this->filter = array_merge($this->filter, $filter);
        return $this;
    }

    /**
     * field 子段设置
     * @param   array|string   $fields
     * @return    $this
     */
    public function field($fields) {
        if($fields == '' || $fields == '*') {
            return $this;
        }
        if(is_string($fields)) {
            $fieldParams = explode(',', $fields);
        }elseif(is_array($fileds)) {
            $fieldParams = $fields;
        } 
        foreach($fieldParams as $field) {
            $projection[$field] = 1; 
        }
        $this->options['projection'] = $projection;
        return $this;
    }

    /**
     * limit 限制数量设置
     * @param   int   $skip
     * @param   int   $offset
     * @return   $this
     */
    public function limit($skip, $offset) {
        $this->options['skip'] = (int) $skip;
        $this->options['limit'] = (int) $offset;
        return $this;
    }

    /**
     * order 子段排序
     * @param   array   $sort
     * @return   $this
     */
    public function order($sort) {
        if(is_string($sort)) {
            list($field,$ordervalue) = explode(',',$sort);
            $this->options['sort'] = [$field=>$ordervalue];
        }
        if(is_array($sort)) {
            $this->options['sort'] = $sort;
        }
        return $this;
    }

    /**
     * find 查询数据
     * @return array
     */
    public function find() {
        $return = [];
        $result = $this->collectionInstance->find($this->filter, $this->options);
        foreach($result as $k => $document) {
            $return[$k] = iterator_to_array($document);
            if(isset($return[$k]['_id'])) {
                $return[$k]['_id'] = (string) $return[$k]['_id'];
            }
        }        
        return $return;
    }

    /**
     * insertMany 插入多条数据
     * @param   array   $insertData
     * @return    object
     */
    public function insert($insertData) {
        if(count($insertData) == count($insertData, 1)) {
            return $this->insertOne($insertData);
        }
        $writeResult = $this->collectionInstance->insertMany($insertData, $this->options);
        $insertId = $writeResult->getInsertedCount();
        if($insertId < 0 || is_null($insertId) || $insertId === false) {
            return false;
        }
        return $insertId;
    }

    /**
     * insertOne 插入一条数据
     * @param   array   $insertData
     * @return    object
     */
    public function insertOne($insertData) {
        $writeResult = $this->collectionInstance->insertOne($insertData, $this->options);
        $insertId = $writeResult->getInsertedCount();
        if($insertId < 0 || is_null($insertId) || $insertId === false) {
            return false;
        }
        return $insertId;
    }

    /**
     * deleteMany 删除多条数据
     * @return void
     */
    public function delete() {
        $deleteResult = $this->collectionInstance->deleteMany($this->filter, $this->options);
        $deleteId = $deleteResult->getDeletedCount();
        if($deleteId < 0 || is_null($deleteId) || $deleteId === false) {
            return false;
        }
        return $deleteId;
    }

    /**
     * deleteMany 删除一条数据
     * @return 
     */
    public function deleteOne() {
        $deleteResult = $this->collectionInstance->deleteOne($this->filter, $this->options);
        $deleteId = $deleteResult->getDeletedCount();
        if($deleteId < 0 || is_null($deleteId) || $deleteId === false) {
            return false;
        }
        return $deleteId;
    }

    /**
     * updateMany 更新多条数据
     * @return void
     */
    public function update() {
        $updateResult = $this->collectionInstance->updateMany($this->filter, $this->options);
        $updateId = $updateResult->writeResult->getUpsertedCount();
        if($updateId == 0 || $updateId  === false) {
            return false;
        }
        return $updateId;
    }

    /**
     * deleteMany 更新一条数据
     * @return 
     */
    public function updateOne() {
        $updateResult = $this->collectionInstance->updateOne($this->filter, $this->options);
        $updateId = $updateResult->writeResult->getUpsertedCount();
        if($updateId == 0 || $updateId  === false) {
            return false;
        }
        return $updateId;
    }

    /**
     * count 计算总数
     * @return int
     */
    public function count() {
        return $this->collectionInstance->count($this->filter, $this->options);
    }

    /**
     * distinct 
     * @param   string   $filedName
     * @return    void
     */
    public function distinct($filedName) {
        return $this->collectionInstance->count($filedName, $this->filter, $this->options);
    }

    /**
     * option 选项设置
     * @param   array   $options
     * @return    $this
     */
    public function option($options) {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
}