<?php

namespace App;

class autoloader {
    /**
     * 构造执行函数
     * @var array
     */
    private $directory;
    private $prefix;
    private $prefixLength;

    public function __construct($baseDirectory = __DIR__) {
         // 当前所在的文件夹路径
        $this->directory = $baseDirectory;
        // 当前命名空间
        $this->prefix = __NAMESPACE__.'\\';
        // 当前命名空间的字符串数
        $this->prefixLength = strlen($this->prefix);
    }
    /**
     * @param string $className 
     * @return boolean
     */
    public function autoload($className) {
        // 判断如果以\命名空间访问的格式符合
        if (0 === strpos($className, $this->prefix)) {
            //分隔出$this->prefixLength个字符串以后的字符返回，再以\为分隔符分隔
            $parts = explode('\\', substr($className, $this->prefixLength));
            // 组合新的路径
            $filepath = $this->directory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts).'.php';
            if (is_file($filepath)) {
                require_once $filepath;
            }
        }
    }
    /**
     * 注册自动加载
     */     
    public static function register($prepend=false) { 
        if (function_exists('__autoload')) {        
            trigger_error('spl_autoload_register() which will bypass your __autoload() and may break your autoloading', E_USER_WARNING);    
        }else {
            spl_autoload_register(array(new self(), 'autoload'), true, $prepend);
        }
    }
}

// include文件时，即完成自动加载的注册
autoloader::register();
