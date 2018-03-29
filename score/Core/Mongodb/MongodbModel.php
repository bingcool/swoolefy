<?php
namespace Swoolefy\Core\Mongodb;

use MongoDB\Client;
use Swoolefy\Core\Mongodb\MongodbCollection;

class MongodbModel {
    /**
     * $mongodbClient mongodb的客户端对象
     * @var null
     */
    public $mongodbClient = null;

    /**
     * $database 默认连接的数据库
     * @var null
     */
    public $database = null;

    /**
     * 配置值
     * @var string
     */
    public $uri = 'mongodb=127.0.0.1:27017';
    public $uriOptions = [];
    public $driverOptions = [];

    /**
     * $databaseObject 数据库对象
     * @var null
     */
    public static $databaseObject = null;

    /**
     * $collectionModels 每个collection的操作对象
     * @var array
     */
    public static $collectionModels = [];

    /**
     * _id 将默认设置成id
     * @var string
     */
    public $_id = null;

    public function __construct($uri='mongodb=127.0.0.1:27017', $uriOptions = [], $driverOptions=[]) {
        $this->uri = $uri;
        $this->uriOptions = $uriOptions;
        $this->driverOptions = $driverOptions;
    }

    /**
     * setDatabase 
     * @param   string   $db
     * @return    object
     */
    public function setDatabase($db = null) {
        if($db) {
            return $this->database = $db;
        }
        if(isset($this->database) && is_string($this->database)) {
            return $this->database;
        }

    }

    /**
     * dbInstanc
     * @param  string   $db
     * @return   object
     */
    public function dbInstance($db = null) {
        if(isset(self::$databaseObject) && is_object(self::$databaseObject)) {
            return  self::$databaseObject;
        }
        $db = $this->setDatabase($db);
        return self::$databaseObject = $this->mongodbClient->$db;
    }

     /**
     * db 返回数据库对象实例
     * @return mixed
     */
    public function db() {
        if(!is_object($this->mongodbClient)) {
            $this->mongodbClient = new Client($this->uri, $this->uriOptions, $this->driverOptions);
        }
        return $this->dbInstance($db = null);
    }

    /**
     * ping 测试是否能够连接mongodb server
     *
     * @return void
     */
    public function ping() {
        $cursor = $this->db()->command([
            'ping' => 1,
        ]);
        return $cursor->toArray()[0]['ok'];
    }

    /**
     *  collection 创建collection对象
     * @param   string  $collection
     * @return    object
     */
    public function collection($collection) {
        if(!is_object($this->mongodbClient)) {
            $this->mongodbClient = new Client($this->uri, $this->uriOptions, $this->driverOptions);
        }

        if(isset(self::$collectionModels[$collection]) && is_object(self::$collectionModels[$collection])) {
            return self::$collectionModels[$collection]; 
        }

        $this->dbInstance();
        return self::$collectionModels[$collection] = new MongodbCollection($collection);
    }

    /**
     * __get 获取collection
     * @param string  $name
     * @return void
     */
    public function __get($name) {
        if(is_string($name)) {
            return $this->collection($name);
        }
        return false;
    }

    /**
     * __destruct 销毁初始化静态变量
     */
    public function __destruct() {
        self::$databaseObject = null;
        self::$collectionModels = [];
    }

}