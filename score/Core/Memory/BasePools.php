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

namespace Swoolefy\Core\Memory;

class BasePools {

	public $pools = [];

	private static $instance;

    static function getInstance(...$args)
    {
        if(!isset(self::$instance)){
        	var_dump('start');
            self::$instance = new static(...$args);
        }
        return self::$instance;
    }

	/**
     * 创建一个数据SplQueue
     * @param  string  $name
     * @param  int  $size
     */
    public function addSplQueue(string $name) {
        if(!isset($this->pools[$name])) {
            $chan = new \SplQueue();
            $this->pools[$name] = $chan;
        }
    }

    /**
     * getSplQueue
     * @param    string   $name
     * @return   mixed
     */
    public function getSplQueue(string $name) {
        if(isset($this->pools[$name])) {
            return $this->pools[$name];
        }else{
            return null;
        }
    }

}