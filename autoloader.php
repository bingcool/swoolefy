<?php
/*************自定义命名空间加载类**************/
class autoloader {
    /**
     * $directory 当前的目录
     * @var [type]
     */
    private $baseDirectory = __DIR__;
   
    /**
     * $prefix 自定义的根命名空间
     * @var array
     */
    private $root_namespace = ['App'];

    /**
     * @param string $className 
     * @return boolean
     */
    public function autoload($className) {
        foreach($this->root_namespace as $namespace) {
            // 判断如果以\命名空间访问的格式符合
            if (0 === strpos($className, $namespace)) {
                //分隔出$this->prefixLength个字符串以后的字符返回，再以\为分隔符分隔
                $parts = explode('\\', $className);
                // 组合新的路径
                $filepath = $this->baseDirectory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts).'.php';
                if (is_file($filepath)) {
                    require_once $filepath;
                }
                // 匹配到符合的,结束循环
                break;
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
