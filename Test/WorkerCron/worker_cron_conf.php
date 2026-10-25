<?php

declare(strict_types=1);

return array_merge(
    //include __DIR__ . "/conf/schedule_local_conf.php",
    include  __DIR__."/conf/schedule_url_conf.php",
    include  __DIR__."/conf/schedule_fork_conf.php",
);