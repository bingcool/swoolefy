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

namespace Swoolefy\Core;

class SwoolefyException {

    const EXCEPTION_ERR = 'error';

    const EXCEPTION_WARNING = 'warning';

    const EXCEPTION_NOTICE = 'notice';

    const EXCEPTION_INFO = 'info';

	/**
	 * fatalError 致命错误捕获,两种情况触发
     * a)代码中执行exit(),die()原生函数，在swoole中是禁止使用这个两个函数的，因为会导致worker退出
     * b)代码中发生异常，throw
     * c)代码执行完毕，由于在这里是worker常驻内存的，register_shutdown_function所注册是在worker进程中的，所以代码执行完毕不会触发，在php-fpm中代码会执行
	 * @return 
	 */
    public static function fatalError() {
        if($e = error_get_last()) {
            switch($e['type']){
                case E_ERROR:
                case E_PARSE:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                    @ob_end_clean();
                    static::shutHalt($e['message'], $errorType = SwoolefyException::EXCEPTION_ERR);
                break;
            }
        }
    }

	/**
     * appException 自定义异常处理
     * @param \Throwable $e 异常对象
     */
    public static function appException($e) {
        $error = array();
        $error['message']   =   $e->getMessage();
        $trace              =   $e->getTrace();
        if('E' == $trace[0]['function']) {
            $error['file']  =   $trace[0]['file'];
            $error['line']  =   $trace[0]['line'];
        }else{
            $error['file']  =   $e->getFile();
            $error['line']  =   $e->getLine();
        }
        $error['trace']     =   $e->getTraceAsString();
        $errorStr = "{$error['message']} in file {$error["file"]} on line {$error['line']} ";
        static::shutHalt($errorStr);
    }

    /**
     * appError 获取用户程序错误
     * @param  int    $errorNo
     * @param  string $errorString
     * @param  string $errorFile
     * @param  int    $errorLine
     * @return void
     */
    public static function appError($errorNo, $errorString, $errorFile, $errorLine) {
    	$errorStr = "{$errorString} in file {$errorFile} on line {$errorLine} ";
      	switch ($errorNo) {
            case E_ERROR:
          		static::shutHalt($errorStr, $errorType = SwoolefyException::EXCEPTION_ERR);
          		break;
            case E_WARNING:
          		static::shutHalt($errorStr, $errorType = SwoolefyException::EXCEPTION_WARNING);
          		break;
            case E_NOTICE:
            	static::shutHalt($errorStr, $errorType = SwoolefyException::EXCEPTION_NOTICE);
           		break;
            default:
            break;
      	}
      return ;
    }

    /**
     * shutHalt 输出错误日志
     * @param string $errorMsg
     * @param string $errorType
     */
    public static function shutHalt($errorMsg, $errorType = SwoolefyException::EXCEPTION_ERR) {
        if(!defined('LOG_PATH')) {
            define('LOG_PATH', START_DIR_ROOT.DIRECTORY_SEPARATOR.APP_NAME);
            if(!is_dir(LOG_PATH)) {
                mkdir(LOG_PATH,0766);
            }
        }
        $logFilePath = rtrim(LOG_PATH,'/').'/runtime.log';
        if(is_file($logFilePath)) {
            $logFilesSize = filesize($logFilePath);
        }

        // 定时清除这个log文件
        if(isset($logFilesSize) && $logFilesSize > 20 * 1024 * 1024) {
            @file_put_contents($logFilePath,'');
        }

        $log = new \Swoolefy\Util\Log;

        switch($errorType) {
            case SwoolefyException::EXCEPTION_ERR:
                  $log->setChannel('Application')->setLogFilePath($logFilePath)->addError($errorMsg);
                 break;
            case SwoolefyException::EXCEPTION_WARNING:
                  $log->setChannel('Application')->setLogFilePath($logFilePath)->addWarning($errorMsg);
                 break;
            case SwoolefyException::EXCEPTION_NOTICE:
                  $log->setChannel('Application')->setLogFilePath($logFilePath)->addNotice($errorMsg);
                 break;
            case SwoolefyException::EXCEPTION_INFO:
                 $log->setChannel('Application')->setLogFilePath($logFilePath)->addInfo($errorMsg);
                 break;
        }
        if(in_array(SWOOLEFY_ENV, [SWOOLEFY_DEV, SWOOLEFY_GRA])) {
            _each($errorMsg);
        }
       
        return;
    }
}