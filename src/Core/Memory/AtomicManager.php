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

    /**
     * @var array
     */
	private $atomicList = [];

    /**
     * @var array
     */
    private $atomicListLong = [];

    /**
     * addAtomic 
     * @param string      $name
     * @param int|integer $int
     */
    public function addAtomic(string $name, int $int = 0) {
        if(!isset($this->atomicList[$name])){
            $atomic = new \Swoole\Atomic($int);
            $this->atomicList[$name] = $atomic;
        }
    }

    /**
     * addAtomicLong 
     * @param string      $name
     * @param int|integer $int
     */
    public function addAtomicLong(string $name, int $int = 0) {
        if(!isset($this->atomicListLong[$name])){
            $atomic = new \Swoole\Atomic\Long($int);
            $this->atomicListLong[$name] = $atomic;
        }
    }

    /**
     * getAtomic 
     * @param  string $name
     * @return mixed
     */
    public function getAtomic(string $name) {
        return $this->atomicList[$name] ?? null;
    }

    /**
     * getAtomicLong
     * @param  string $name
     * @return mixed
     */
    public function getAtomicLong(string $name) {
        return $this->atomicListLong[$name] ?? null;
    } 
}