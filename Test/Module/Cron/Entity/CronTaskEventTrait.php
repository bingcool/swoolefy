<?php
namespace Test\Module\Cron\Entity;

trait CronTaskEventTrait
{
    protected function onBeforeSave(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function onBeforeInsert(): bool
    {
        return true;
    }

    protected function onAfterInsert()
    {
    }

    protected function onBeforeUpdate(): bool
    {
        return true;
    }

    protected function onAfterUpdate()
    {
    }
}