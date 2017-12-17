<?php
namespace Swoolefy\Core\Mongodb;

/**
 * return call_user_func_array([$this->collectionInstance, $method], $argc);
 */
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
    public function setDatabase($db=null) {
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
    public function dbInstance($db=null) {
        if(isset(self::$databaseObject) && is_object(self::$databaseObject)) {
            return  self::$databaseObject;
        }
        $db = $this->setDatabase($db);
        return self::$databaseObject = $this->mongodbClient->$db;
    }

     /**
     * 返回数据库对象实例
     * @return mixed
     */
    public function Db() {
       return $this->dbInstance();
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
     * __destruct 销毁初始化静态变量
     */
    public function __destruct() {
        self::$databaseObject = null;
        self::$collectionModels = [];
    }

}