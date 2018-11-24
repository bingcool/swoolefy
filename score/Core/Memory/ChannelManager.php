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

class ChannelManager {

    use \Swoolefy\Core\SingletonTrait;

    private $list = [];

    /**
     * 创建一个数据Channel
     * @param  string  $name
     * @param  int  $size
     */
    public function addChannel(string $name, int $size = 256 * 1024) {
        if(!isset($this->list[$name])) {
            $chan = new \Swoole\Channel($size);
            $this->list[$name] = $chan;
        }
    }

    /**
     * getChannel
     * @param    string   $name
     * @return   mixed
     */
    public function getChannel(string $name) {
        if(isset($this->list[$name])) {
            return $this->list[$name];
        }else{
            return null;
        }
    }
}