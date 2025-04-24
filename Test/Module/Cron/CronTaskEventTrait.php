<?php
namespace Test\Module\Cron;

use Test\Model\ClientModel;

trait CronTaskEventTrait
{
    protected function onBeforeSave(): bool
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
        return true;
    }

    /**
     * @return bool
     */
    protected function onBeforeInsert(): bool
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
        return true;
    }

    protected function onAfterInsert()
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
    }

    protected function onBeforeUpdate(): bool
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
        return true;
    }

    protected function onAfterUpdate()
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
    }
}