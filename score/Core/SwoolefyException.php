<?php
namespace Swoolefy\Core;

class SwoolefyException {
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
                self::shutHalt($e['message']);
                break;
            }
        }
    }

	/**
     * appException 自定义异常处理
     * @param mixed $e 异常对象
     */
    public static function appException($e) {
        $error = array();
        $error['message']   =   $e->getMessage();
        $trace              =   $e->getTrace();
        if('E'==$trace[0]['function']) {
            $error['file']  =   $trace[0]['file'];
            $error['line']  =   $trace[0]['line'];
        }else{
            $error['file']  =   $e->getFile();
            $error['line']  =   $e->getLine();
        }
        $error['trace']     =   $e->getTraceAsString();
        $errorStr = $error['file'].' 第'.$error['line'].'行:'.$error['message'];
        self::shutHalt($errorStr);
    }

    /**
     * appError 获取用户程序错误
     * @param    $errno  
     * @param    $errstr 
     * @param    $errfile
     * @param    $errline
     * @return           
     */
    public static function appError($errno, $errstr, $errfile, $errline) {

      switch ($errno) {
          case E_ERROR:
          case E_PARSE:
          case E_CORE_ERROR:
          case E_COMPILE_ERROR:
          case E_USER_ERROR:
            ob_end_clean();
            $errorStr = "$errstr ".$errfile." 第 $errline 行.";
            self::shutHalt($errorStr);
            break;
          default:
            break;
      }
      return ;
    }

    /**
     * shutHalt 错误输出日志
     * @param  $error 错误
     * @return void
     */
    public static function shutHalt($errorMsg) {
      $logFilePath = trim(LOG_PATH,'/').'/runtime.log';
      if(is_file($logFilePath)) $logFilesSize = filesize($logFilePath);
      // 定时清除这个log文件
      if($logFilesSize > 1024 * 20) {
        @file_put_contents($logFilePath,'');
      }
      Application::$app->log->setChannel('Application')->setLogFilePath($logFilePath)->addError($errorMsg);
      return;
    }
}