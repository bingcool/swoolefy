<?php
namespace Test\Module\Order;

use Common\Library\Component\ListItemFormatter;

class OrderFormatter extends ListItemFormatter
{
    protected function buildMapData($list)
    {
        $this->mapData['user_info'] = [];
    }

    protected function format($data): array
    {
        $data['bg'] = 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm';
        return $data;
    }
}