<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db\Concern;

use think\db\exception\DbException as Exception;

/**
 * 乐观锁
 */
trait OptimLock
{
    protected function getOptimLockField()
    {
        return property_exists($this, 'optimLock') && isset($this->optimLock) ? $this->optimLock : 'lock_version';
    }

    /**
     * 数据检查
     * @access protected
     * @return void
     */
    protected function checkData(): void
    {
        $this->isExists() ? $this->updateLockVersion() : $this->recordLockVersion();
    }

    /**
     * 记录乐观锁
     * @access protected
     * @return void
     */
    protected function recordLockVersion(): void
    {
        $optimLock = $this->getOptimLockField();

        if ($optimLock) {
            $this->set($optimLock, 0);
        }
    }

    /**
     * 更新乐观锁
     * @access protected
     * @return void
     */
    protected function updateLockVersion(): void
    {
        $optimLock = $this->getOptimLockField();

        if ($optimLock && $lockVer = $this->getOrigin($optimLock)) {
            // 更新乐观锁
            $this->set($optimLock, $lockVer + 1);
        }
    }

    public function getWhere()
    {
        $where     = parent::getWhere();
        $optimLock = $this->getOptimLockField();

        if ($optimLock && $lockVer = $this->getOrigin($optimLock)) {
            $where[] = [$optimLock, '=', $lockVer];
        }

        return $where;
    }

    protected function checkResult($result): void
    {
        if (!$result) {
            throw new Exception('record has update');
        }
    }

}
