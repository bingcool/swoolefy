<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Response\CronTaskManager\ListTasksPageResult;
use Swoolefy\Http\BasePageResultResponse;

class ListTasksResponse extends BasePageResultResponse
{
    protected ListTasksPageResult $data;

    public function __construct(ListTasksPageResult $data)
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        $items = [];
        foreach ($this->data->getList() as $row) {
            $item = $row->toDeepArray();
            $item['groupId'] = $row->getGroupId();
            $item['groupName'] = $row->getGroupName();
            $items[] = $item;
        }

        return [
            'items' => $items,
            'list' => $items,
            'page' => $this->data->getPage(),
            'pageSize' => $this->data->getPageSize(),
            'total' => $this->data->getTotal(),
        ];
    }
}
