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

namespace Swoolefy\Core\Memory;

class AtomicManager
{

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
     * @param string $name
     * @param int $initValue
     */
    public function addAtomic(string $name, int $initValue = 0)
    {
        if (!isset($this->atomicList[$name])) {
            $atomic = new \Swoole\Atomic($initValue);
            $this->atomicList[$name] = $atomic;
        }
    }

    /**
     * addAtomicLong
     * @param string $name
     * @param int $initValue
     * @return void
     */
    public function addAtomicLong(string $name, int $initValue = 0)
    {
        if (!isset($this->atomicListLong[$name])) {
            $atomic = new \Swoole\Atomic\Long($initValue);
            $this->atomicListLong[$name] = $atomic;
        }
    }

    /**
     * getAtomic
     * @param string $name
     * @return \Swoole\Atomic
     */
    public function getAtomic(string $name)
    {
        return $this->atomicList[$name] ?? null;
    }

    /**
     * getAtomicLong
     * @param string $name
     * @return \Swoole\Atomic\Long
     */
    public function getAtomicLong(string $name)
    {
        return $this->atomicListLong[$name] ?? null;
    }
}