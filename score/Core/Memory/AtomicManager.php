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

class AtomicManager {

	use \Swoolefy\Core\SingletonTrait;

	private $atomicList = [];
    private $atomicListForLong = [];

    public function addAtomic(string $name, int $int = 0) {
        if(!isset($this->atomicList[$name])){
            $atomic = new \Swoole\Atomic($int);
            $this->atomicList[$name] = $atomic;
        }
    }

    public function addAtomicLong(string $name, int $int = 0) {
        if(!isset($this->atomicListForLong[$name])){
            $atomic = new \Swoole\Atomic\Long($int);
            $this->atomicListForLong[$name] = $atomic;
        }
    }

    public function getAtomic(string $name) {
        if(isset($this->atomicList[$name])){
            return $this->atomicList[$name];
        }else{
            return null;
        }
    }

    public function getAtomicLong(string $name) {
        if(!isset($this->atomicListForLong[$name])){
            return $this->atomicListForLong[$name];
        }else{
            return null;
        }
    }
 
}