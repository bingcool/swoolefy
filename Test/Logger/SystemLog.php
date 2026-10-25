<?php

declare(strict_types=1);

namespace Test\Logger;

class SystemLog extends AbstractLog
{
    public static $infoLogType ='system_error_log';
}