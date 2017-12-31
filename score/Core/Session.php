<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Application;
use Swoolefy\Core\MGeneral;

class Session {

    const SESSION_CACHE = 'redis_session';

    public $cache_prefix = 'phpsess_';

    public $cookie_key = 'PHPSESSID';

    public $cache_driver = 'redis';
    public $driver = null;

    public  $cookie_lifetime = 7776000;
    public  $session_lifetime = 0;
    public  $cookie_domain = '*';
    public  $cookie_path = '/';

    public $isStart = false;

    public $session_id;

    /**
     * 是否为只读，只读不需要保存
     * @var null
     */
    public $readonly ;

    public function __construct(array $config=[]) {
        if(isset($config['cache_prefix'])  && !empty($config['cache_prefix'])) {
            $this->cache_prefix = $config['cache_prefix'];
        }
        if(isset($config['cookie_key ']) && !empty($config['cookie_key '])) {
            $this->coookie_key = $config['cookie_key '];
        }
        if(isset($config['cookie_lifetime']) && !empty($config['cookie_lifetime'])) {
            $this->cookie_lifetime = $config['cookie_lifetime'];
        }
        if(!isset($config['session_lifetime'])) {
            $this->session_lifetime = $this->cookie_lifetime + 604800;
        }else {
            $this->session_lifetime = $config['session_lifetime'];
        }
        if(isset($config['cookie_path']) && !empty($config['cookie_path'])) {
            $this->cookie_path = $config['cookie_path'];
        }
        if(isset($config['cache_driver']) && !empty($config['cache_driver'])) {
            $this->cache_driver = $config['cache_driver'];
        }
        if(isset($config['cache_driver'])) {
            $this->cookie_domain = $config['cookie_domain'];
        }
       
    }

    /**
     * start 开启session
     * @param boolean $readonly
     * @return void
     */
    public function start($readonly = false) {
         /**注册钩子程序，在请求结束后保存sesion */
        Application::$app->afterRequest([$this,'save']);
    
        $driver_class = $this->cache_driver;
        $this->driver = Application::$app->$driver_class;
        $this->isStart = true;
        $this->readonly = $readonly;
        $sess_id = $this->session_id;
        if (empty($sessid)){
            $sess_id = Application::$app->request->cookie[$this->cookie_key];
            if (empty($sess_id)) {
                $sess_id = MGeneral::randmd5(40);
                Application::$app->response->cookie($this->cookie_key, $sess_id, time() + $this->cookie_lifetime, $this->cookie_path, $this->cookie_domain);
                $sess_id = $this->session_id;
            }
        }
        $_SESSION = $this->load($sess_id);
        return true;
    }

    /**
     * load 加载获取session数据
     * @param  string  $sessid
     * @return   array
     */
    public function load($sess_id) {
        $this->session_id = $sess_id;
        $data = $this->driver->get($this->cache_prefix . $sess_id);
        //先读数据，如果没有，就初始化一个
        if (!empty($data)) {
            return unserialize($data);
        }else {
            return [];
        }
    }

    /**
     * 保存Session
     * @return bool
     */
    public function save() {
        if (!$this->isStart || $this->readonly) {
            return true;
        }
        //设置为Session关闭状态
        $this->isStart = false;
        $key = $this->cache_prefix . $this->session_id;
        // 如果没有设置SESSION,则不保存,防止覆盖
        if(empty($_SESSION)) {
            return false;
        }
        return $this->driver->setex($key, $this->session_lifetime, serialize($_SESSION));
    }

    /**
     * getSessionId 获取session_id
     * @return string
     */
    public function getSessionId() {
        return $this->session_id;
    }

    /**
     * set 设置session保存数据
     *
     * @param   string   $key
     * @param   mixed  $data
     * @return    true
     */
    public function set($key, $data) {
        $_SESSION[$key] = $data;
        return true;
    }

    /**
     * get 获取session的数据
     * @param   string  $key
     * @return   mixed
     */
    public function get($key) {
        return $_SESSION[$key];
    }
    
}