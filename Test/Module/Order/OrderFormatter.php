<?php
namespace Test\Module\Order;

use Test\Library\ListItemFormatter;
use Test\Library\ListObject;

class OrderFormatter extends ListItemFormatter
{

    protected function format($data)
    {
        return $data;
    }
}