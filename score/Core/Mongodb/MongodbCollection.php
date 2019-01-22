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

namespace Swoolefy\Core\Mongodb;

class MongodbCollection {

    /**
     * _id 将默认设置成id
     */
    public $_id = null;

    /**
     * $collectionInstance  collection实例
     * @var null
     */
    public $collectionInstance = null;

    /**
     * __construct
     * @param string $collection
     */
    public function __construct($collection, $_id, $databaseObject) {
        $this->collectionInstance = $databaseObject->$collection;
        $this->_id = $_id;
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
     * parseFilter 条件分析
     * @param   array   $filter
     * @return    $this
     */
    protected function parseFilter($filter = []) {
        if(isset($filter['_id'])) {
            if(!$filter['_id'] instanceof \MongoDB\BSON\ObjectId) {
                $filter['_id'] = new \MongoDB\BSON\ObjectId($filter['_id']);
            }
            if(isset($filter[$this->_id])) {
                unset($filter[$this->_id]);
            }
        }else {
            $keys = array_keys($filter);
            if(in_array($this->_id, $keys)) {
                if($filter[$this->_id] instanceof \MongoDB\BSON\ObjectId) {
                    $filter['_id'] = $filter[$this->_id];
                }else {
                    $filter['_id'] = new \MongoDB\BSON\ObjectId($filter[$this->_id]);
                }
                unset($filter[$this->_id]);
            }
        }
        return $filter;
    }

    /**
     * find 查询数据
     * @return array
     */
    public function find($filter = [], array $options = []) {
        $result = [];
        $filter = $this->parseFilter($filter);
        $documents = $this->collectionInstance->find($filter, $options);
        if($documents) {
            foreach($documents as $k => $document) {
                $result[$k] = iterator_to_array($document);
                if(isset($result[$k]['_id'])) {
                    if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                        $result[$k][$this->_id] = (string) $result[$k]['_id'];
                        unset($result[$k]['_id']);
                    }else {
                        $result[$k]['_id'] = (string) $result[$k]['_id'];
                    }  
                }
            }
        }      
        return $result;
    }

    /**
     * findOne 查找一个文档
     * @param  array  $filter
     * @param  array  $options
     * @return array        
     */
    public function findOne($filter = [], array $options = []) {
        $filter = $this->parseFilter($filter);
        $documents = $this->collectionInstance->findOne($filter, $options);
        if($documents) {
            $document = iterator_to_array($documents);
        }
        if(isset($document['_id'])) {
            if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                $document[$this->_id] = (string) $document['_id'];
                unset($document['_id']);
            }else {
                $document['_id'] = (string) $document['_id'];
            }
        } 
        return $document;
    }

    /**
     * findOneAndDelete 返回将要被删除的文档
     * @param  array $filter
     * @param  array  $options
     * @return mixed
     */
    public function findOneAndDelete($filter, array $options = []) {
        $filter = $this->parseFilter($filter);
        $result = $this->collectionInstance->findOneAndDelete($filter, $options);
        if(is_object($result)) {
            $document = iterator_to_array($result);
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else if(is_array($result)) {
            $document = $result;
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else {
            // 没有匹配到文档返回null
            $document = $result;
        }
        
        return $document;
    }

    /**
     * findOneAndReplace 返回替换后的文档
     * @param  array $filter     
     * @param  array $replacement
     * @param  array  $options    
     * @return mixed             
     */
    public function findOneAndReplace($filter, $replacement, array $options = []) {
        $filter = $this->parseFilter($filter);
        $result = $this->collectionInstance->findOneAndReplace($filter, $replacement, $options);
        if(is_object($result)) {
            $document = iterator_to_array($result);
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else if(is_array($result)) {
            $document = $result;
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else {
            $document = $result;
        }
        
        return $document;
    }

    /**
     * findOneAndUpdate 返回更新后的文档
     * @param  array $filter
     * @param  array $update
     * @param  array  $options
     * @return mixed
     */
    public function findOneAndUpdate($filter, $update, array $options = []) {
        $filter = $this->parseFilter($filter);
        $result = $this->collectionInstance->findOneAndUpdate($filter, $update, $options);
        if(is_object($result)) {
            $document = iterator_to_array($result);
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else if(is_array($result)) {
            $document = $result;
            if(isset($document['_id'])) {
                if(!is_null($this->_id) &&  ($this->_id != '_id')) {
                    $document[$this->_id] = (string) $document['_id'];
                    unset($document['_id']);
                }else {
                    $document['_id'] = (string) $document['_id'];
                }
            }
        }else {
            // 没有找到对应的更新文档，返回null
            $document = $result;
        }
        
        return $document;
    }

    /**
     * insertMany 插入多个文档
     * @param  array  $documents
     * @param  array  $options
     * @return 
     */
    public function insertMany(array $documents, array $options = []) {
        return $this->insert($documents, $options);
    }

    /**
     * insertMany 插入多个文档
     * @param   array   $documents
     * @param   array   $options
     * @return    int
     */
    public function insert(array $documents, array $options = []) {
        if(count($documents) == 1) {
            return $this->insertOne($documents, $options);
        }
        $writeResult = $this->collectionInstance->insertMany($documents, $options);
        $insertId = $writeResult->getInsertedCount();
        if($insertId < 0 || is_null($insertId) || $insertId === false) {
            return false;
        }
        return $insertId;
    }

    /**
     * insertOne 插入一条数据
     * @param   array   $document
     * @param   array   $options
     * @return    int
     */
    public function insertOne(array $document, array $options = []) {
        $writeResult = $this->collectionInstance->insertOne($document, $options);
        $insertId = $writeResult->getInsertedCount();
        if($insertId < 0 || is_null($insertId) || $insertId === false) {
            return false;
        }
        return $insertId;
    }

   /**
     * deleteMany 删除多个文档
     * @param   array $filter
     * @param   array options
     * @return  int|boolean 
     */
    public function delete($filter, array $options = []) {
        $filter = $this->parseFilter($filter);
        $deleteResult = $this->collectionInstance->deleteMany($filter, $options);
        $deleteId = $deleteResult->getDeletedCount();
        if($deleteId < 0 || is_null($deleteId) || $deleteId === false) {
            return false;
        }
        return $deleteId;
    }

    /**
     * deleteMany 删除多个文档
     * @param   array $filter
     * @param   array options
     * @return  int|boolean 
     */
    public function deleteMany($filter, array $options = []) {
        return $this->delete($filter, $options);
    }

    /**
     * deleteMany 删除一个文档
     * @param   array $filter
     * @param   array options
     * @return  int|boolean 
     */
    public function deleteOne($filter, array $options = []) {
        $filter = $this->parseFilter($filter);
        $deleteResult = $this->collectionInstance->deleteOne($filter, $options);
        $deleteId = $deleteResult->getDeletedCount();
        if($deleteId < 0 || is_null($deleteId) || $deleteId === false) {
            return false;
        }
        return $deleteId;
    }

     /**
     * updateMany 更新多个文档
     * @param  array  $filter
     * @param  array  $update
     * @param  array  $options
     * @return int|boolean
     */
    public function update($filter, $update, array $options = []) {
        $filter = $this->parseFilter($filter);
        $updateResult = $this->collectionInstance->updateMany($filter, $update, $options);
        $updateId = $updateResult->getUpsertedCount();
        if($updateId == 0 || $updateId  === false) {
            return false;
        }
        return $updateId;
    }

    /**
     * updateMany 更新多个文档
     * @param  array  $filter
     * @param  array  $update
     * @param  array  $options
     * @return int|boolean
     */
    public function updateMany($filter, $update, array $options = []) {
        return $this->update($filter, $update, $options);
    }

    /**
     * updateMany 更新一个文档
     * @param  array  $filter
     * @param  array  $update
     * @param  array  $options
     * @return int|boolean
     */
    public function updateOne($filter, $update, array $options = []) {
        $filter = $this->parseFilter($filter);
        $updateResult = $this->collectionInstance->updateOne($filter, $update, $options);
        $updateId = $updateResult->getUpsertedCount();
        if($updateId == 0 || $updateId  === false) {
            return false;
        }
        return $updateId;
    }

    /**
     * count 计算总数
     * @return int
     */
    public function count($filter, array $options = []) {
        return $this->collectionInstance->count($filter, $options);
    }

    /**
     * distinct 
     * @param   string   $filedName
     * @return    mixed
     */
    public function distinct($fieldName, $filter = [], array $options = []) {
        $filter = $this->parseFilter($filter);
        return $this->collectionInstance->distinct($fieldName, $filter, $options);
    }

    /**
     * 本类找不到函数时,自动重载collection类的原始函数
     * @param   string    $method
     * @param   mixed    $argc
     * @return    mixed
     */
    public function __call($method, $args) {
        return call_user_func_array([$this->collectionInstance, $method], $args);
    }
}