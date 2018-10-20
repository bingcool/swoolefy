<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Crontab;

use Cron\CronExpression;
use Swoolefy\Core\Timer\TickManager;

class CrontabManager {

	use \Swoolefy\Core\SingletonTrait;

	protected $cron_tasks = [];

	public function addRule(string $cron_name, string $expression, $func) {
		if(class_exists('Cron\\CronExpression')) {
			if(CronExpression::isValidExpression($expression)) {
				if(is_array($func) && count($func) == 2) {
					$cron_name_key = md5($cron_name);
					if(!isset($this->cron_tasks[$cron_name_key])) {
						$this->cron_tasks[$cron_name_key] = [$expression, $func];
						TickManager::tickTimer(1000, $func, $expression);
					}
					unset($cron_name_key);
				}
			}else {
				throw new \Exception("crontab expression foramt is wrong, please check it", 1);
			}
		}else {
			throw new \Exception("want to use crontab, you need to install Cron\CronExpression", 1);	
		}
	}

	public function getCronTaskByName(string $cron_name) {
		$cron_name_key = md5($cron_name);
		if(isset($this->cron_tasks[$cron_name_key])) {
			return $this->cron_tasks[$cron_name_key];
		}
		return null;
	}

}