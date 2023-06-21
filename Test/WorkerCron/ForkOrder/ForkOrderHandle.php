<?php
date_default_timezone_set('Asia/Shanghai');
file_put_contents('/home/wwwroot/swoolefy/Test/WorkerCron/ForkOrder/order.log','date='.date('Y-m-d H:i:s').',pid='.getmypid()."\n",FILE_APPEND);

