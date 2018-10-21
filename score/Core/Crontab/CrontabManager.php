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
use Swoolefy\Core\Application;
use Swoolefy\Core\Timer\TickManager;

class CrontabManager {

	use \Swoolefy\Core\SingletonTrait;

	protected $cron_tasks = [];

	public function addRule(string $cron_name, string $expression, $func) {
		if(class_exists('Cron\\CronExpression')) {
			if(CronExpression::isValidExpression($expression)) {
				if(is_callable($func)) {
					$cron_name_key = md5($cron_name);
					if(is_array($func)) {
						if(!isset($this->cron_tasks[$cron_name_key])) {	
							$this->cron_tasks[$cron_name_key] = [$expression, $func];
							TickManager::tickTimer(1000, $func, $expression);
						}
					}else {
						if(!isset($this->cron_tasks[$cron_name_key])) {
							$this->cron_tasks[$cron_name_key] = [$expression, $func];
							swoole_timer_tick(1000, function($timer_id, $expression) use($func) {
								$cronInstance = new CronController();
								$cronInstance->runCron($expression, $func);
							
								if(method_exists("Swoolefy\\Core\\Application", 'removeApp')) {
	                				Application::removeApp();
	           					}
							}, $expression);
						}
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

	public function getCronTaskByName(string $cron_name = null) {
		if($cron_name) {
			$cron_name_key = md5($cron_name);
			if(isset($this->cron_tasks[$cron_name_key])) {
				return $this->cron_tasks[$cron_name_key];
			}
			return null;
		}
		return $this->cron_tasks;
	}

}