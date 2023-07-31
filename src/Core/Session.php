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

namespace Swoolefy\Core;

use Swoolefy\Core\Application;
use Swoolefy\Util\Helper;

class Session
{
    /**
     * $driver 缓存驱动的实例
     * @var string
     */
    public $driver = null;

    /**
     * $session 寄存session的数据
     * @var array
     */
    protected $_SESSION = [];

    /**
     * $isStart 是否已经启动session
     * @var bool
     */
    protected $isStart = false;

    /**
     * $session_id sessio_id
     * @var string
     */
    protected $session_id;

    /**
     * 是否为只读，只读不需要保存
     * @var null
     */
    public $readonly;

    /**
     * $cache_driver session的 缓存驱动
     * @var string
     */
    public $cache_driver = 'redis';

    /**
     * $cookie_key cookie的session的key
     * @var string
     */
    public $cookie_key = 'PHPSESSID';

    /**
     * cookie的设置
     * @var int
     */
    public $cookie_lifetime  = 7776000;

    /**
     * @var int|mixed
     */
    public $session_lifetime = 0;

    /**
     * @var mixed|string
     */
    public $cookie_domain = '';

    /**
     * @var mixed|string
     */
    public $cookie_path = '/';

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['cookie_key']) && !empty($config['cookie_key'])) {
            $this->cookie_key = $config['cookie_key'];
        }

        if (isset($config['cookie_lifetime']) && !empty($config['cookie_lifetime'])) {
            $this->cookie_lifetime = $config['cookie_lifetime'];
        }

        if (!isset($config['session_lifetime'])) {
            $this->session_lifetime = $this->cookie_lifetime + 7200;
        } else {
            if ($config['session_lifetime'] <= $config['cookie_lifetime']) {
                $this->session_lifetime = $this->cookie_lifetime + 7200;
            } else {
                $this->session_lifetime = $config['session_lifetime'];
            }
        }

        if (isset($config['cookie_path']) && !empty($config['cookie_path'])) {
            $this->cookie_path = $config['cookie_path'];
        }

        if (isset($config['cache_driver']) && !empty($config['cache_driver'])) {
            $this->cache_driver = $config['cache_driver'];
        }

        if (isset($config['cookie_domain'])) {
            $this->cookie_domain = $config['cookie_domain'];
        }

    }

    /**
     * start session
     * @param bool $readonly
     * @return bool
     * @throws \Exception
     */
    public function start(bool $readonly = false)
    {
        /**
         * 注册钩子程序,在请求结束后保存session,防止多次注册
         */
        $app = Application::getApp();
        if (!$this->isStart) {
            $app->afterRequest([$this, 'save']);
        }

        $driver_component_name = $this->cache_driver;
        $this->driver = $app->get($driver_component_name);
        $this->isStart = true;
        $this->readonly = $readonly;
        $cookie_session_id = isset($app->request->cookie[$this->cookie_key]) ? $app->request->cookie[$this->cookie_key] : null;
        $this->session_id = $cookie_session_id;
        if (empty($cookie_session_id)) {
            $sess_id = Helper::randMd5(40);
            $app->response->cookie($this->cookie_key, $sess_id, time() + $this->cookie_lifetime, $this->cookie_path, $this->cookie_domain, false, false);
            $this->session_id = $sess_id;
        }
        $this->_SESSION = $this->load($this->session_id);
        return true;
    }

    /**
     * load 加载获取session数据
     * @param string $sess_id
     * @return array
     */
    protected function load(string $sess_id)
    {
        if (!$this->session_id) {
            $this->session_id = $sess_id;
        }
        $data = $this->driver->get($sess_id);
        //先读数据，如果没有，就初始化一个
        if (!empty($data)) {
            return unserialize($data);
        } else {
            return [];
        }
    }

    /**
     * 保存Session
     * @return bool
     */
    public function save()
    {
        if (!$this->isStart || $this->readonly) {
            return true;
        }
        //设置为Session关闭状态
        $this->isStart = false;
        // 如果没有设置SESSION,则不保存,防止覆盖
        if (empty($this->_SESSION)) {
            return false;
        }
        return $this->driver->setex($this->session_id, $this->session_lifetime, serialize($this->_SESSION));
    }

    /**
     * @param string|null $cookie_key
     * @return $this
     */
    public function setCookieKey(string $cookie_key = null)
    {
        if ($cookie_key) {
            $this->cookie_key = $cookie_key;
        }
        return $this;
    }

    /**
     * @param string $driver
     * @return $this
     */
    public function setCacheDriver($driver = null)
    {
        if ($driver) {
            $this->cache_driver = $driver;
        }
        return $this;
    }

    /**
     * @param int $cookie_lifetime
     * @param int|null $session_lifetime
     */
    public function setSessionIdExpireTime(int $cookie_lifetime = 0, int $session_lifetime = 0)
    {
        if (!empty($cookie_lifetime)) {
            $this->cookie_lifetime = $cookie_lifetime;
        }
        if (empty($session_lifetime)) {
            $this->session_lifetime = $this->cookie_lifetime + 7200;
        } else {
            if ($session_lifetime <= $cookie_lifetime) {
                $this->session_lifetime = $this->cookie_lifetime + 7200;
            } else {
                $this->session_lifetime = $session_lifetime;
            }
        }

        return $this;
    }

    /**
     * @param string|null $path
     * @return $this
     */
    public function setCookiePath(string $path = null)
    {
        if ($path) {
            $this->cookie_path = $path;
        }
        return $this;
    }

    public function setCookieDomain(string $domain = null)
    {
        if ($domain) {
            $this->cookie_domain = $domain;
        }
        return $this;
    }

    /**
     * getSessionId 获取session_id
     * @return string
     */
    public function getSessionId()
    {
        return $this->session_id;
    }

    /**
     * set 设置session保存数据
     * @param string $key
     * @param mixed $data
     * @return bool
     */
    public function set(string $key, $data)
    {
        if (is_string($key) && isset($data)) {
            $this->_SESSION[$key] = $data;
            return true;
        }
        return false;
    }

    /**
     * get 获取session的数据
     * @param string $key
     * @return mixed
     */
    public function get(string $key = null)
    {
        if (is_null($key)) {
            return $this->_SESSION;
        }
        return $this->_SESSION[$key];
    }

    /**
     * has 是否存在某个key
     * @param string $key
     * @return bool
     */
    public function has(string $key)
    {
        if (!$key) {
            return false;
        }
        return isset($this->_SESSION[$key]);
    }

    /**
     * getSessionTtl 获取session对象的剩余生存时间
     * @return int
     */
    public function getSessionTtl()
    {
        $isExists = $this->driver->exists($this->session_id);
        $isExists && $ttl = $this->driver->ttl($this->session_id);
        if ($ttl >= 0) {
            return $ttl;
        }
        return null;
    }

    /**
     * delete 删除某个key
     * @param string $key
     * @return bool
     */
    public function delete(string $key)
    {
        if ($this->has($key)) {
            unset($this->_SESSION[$key]);
            return true;
        }
        return false;
    }

    /**
     * clear 清空某个session
     * @return bool
     */
    public function destroy()
    {
        if (!empty($this->_SESSION)) {
            $this->_SESSION = [];
            // 使cookie失效
            setcookie($this->cookie_key, $this->session_id, time() - 600, $this->cookie_path, $this->cookie_domain);
            // redis中完全删除session_key
            return $this->driver->del($this->session_id);
        }
        return false;
    }

    /**
     * reGenerateSessionId 重新生成session_id
     * @param bool $ismerge 生成新的session_id是否继承合并当前session的数据，默认true,如需要产生一个完全新的空的$this->_SESSION，可以设置false
     * @return void
     * @throws \Exception
     */
    public function reGenerateSessionId(bool $ismerge = true)
    {
        $session_data = $this->_SESSION;
        // 先cookie的session_id失效
        setcookie($this->cookie_key, $this->session_id, time() - 600, $this->cookie_path, $this->cookie_domain);
        // 设置session_id=null
        $this->session_id = null;
        // 产生新的session_id和返回空的$_SESSION数组
        $this->start();
        if ($ismerge) {
            $this->_SESSION = array_merge($this->_SESSION, $session_data);
        }
    }

}