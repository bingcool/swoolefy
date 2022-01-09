<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core;

use Smarty;
use Swoolefy\Core\Application;

class View
{
    /**
     * $view smarty对象
     * @var null
     */
    public $view = null;

    /**
     * $content_type 响应文档类型
     * @var null
     */
    public $content_type = null;

    /**
     * $gzip_level 压缩等级,与$enable_gzip相关联
     * @var integer
     */
    public $gzip_level = 2;

    /**
     * $write_size 分段返回的大小，默认20000字节(稍微小于2M)
     * @var integer
     */
    public $write_size = 20000;

    /**
     * $enable_gzip 是否开启压缩,压缩功能由nginx实现即可gzip,不要增加swoole的开销，而且数据分多段返回时会出现数据乱码
     * 建议不要开启这个压缩功能,这样$write_size无论设置多大分段(当然<2M)返回都不会乱码
     * @var boolean
     */
    public $enable_gzip = false;

    /**
     * View constructor.
     * @param string $contentType
     */
    public function __construct($contentType = 'text/html')
    {
        if (class_exists('Smarty')) {
            $smarty = new Smarty;
            $smarty->setCompileDir(SMARTY_COMPILE_DIR);
            $smarty->setCacheDir(SMARTY_CACHE_DIR);
            $smarty_template_path = rtrim(SMARTY_TEMPLATE_PATH) . '/';
            $smarty->setTemplateDir($smarty_template_path);
            $this->view = $smarty;
        }
        $this->content_type = $contentType;
        isset(Application::getApp()->app_conf['gzip_level']) && $this->gzip_level = (int)Application::getApp()->app_conf['gzip_level'];
    }

    /**
     * assign 赋值
     * @param    $name
     * @param    $value
     * @return
     */
    public function assign($name, $value)
    {
        $this->view->assign($name, $value);
    }

    /**
     * mAssign 批量赋值
     * @param    $arr
     * @return   boolean|null
     */
    public function multiAssign(array $arr = [])
    {
        if (!empty($arr)) {
            if (is_string($arr)) {
                return false;
            }
            foreach ($arr as $name => $value) {
                $this->assign($name, $value);
            }
            return true;
        }
        return false;
    }

    /**
     * display 渲染视图成字符串
     * @param string $template_file
     * @return  mixed
     */
    public function display($template_file = null)
    {
        $template_file = ltrim($template_file);
        if (stripos($template_file, '@') === 0) {
            $template_file = substr($template_file, 1);
            $this->callFetch($template_file);
        } else {
            $this->redirectFetch($template_file);
        }
    }

    /**
     * redirectFetch 直接渲染对应的模块的模板
     * @param string $template_file
     * @return  mixed
     */
    protected function redirectFetch($template_file)
    {
        $module = Application::getApp()->getModuleId();
        $controller = Application::getApp()->getControllerId();
        $action = Application::getApp()->getActionId();
        if (!$template_file) {
            $template_file = $action . '.html';
        }
        $filePath = SMARTY_TEMPLATE_PATH . $controller . '/' . $template_file;
        $fetchFile = $controller . '/' . $template_file;
        if (!is_null($module)) {
            $filePath = SMARTY_TEMPLATE_PATH . $module . '_' . $controller . '/' . $template_file;
            $fetchFile = $module . '_' . $controller . '/' . $template_file;
        }
        if (is_file($filePath)) {
            $tpl = $this->view->fetch($fetchFile, md5($fetchFile), md5($fetchFile));
        } else {
            $tpl = $template_file;
        }
        $response = @Application::getApp()->response;
        $response->header('Content-Type', $this->content_type . '; charset=utf-8');
        // 分段返回数据,2M左右一段
        $p = 0;
        $size = $this->write_size;
        while ($data = mb_substr($tpl, $p++ * $size, $size, 'utf-8')) {
            $response->write($data);
        }
    }

    /**
     * callFetch 跨模块调用渲染模板
     * @param string $template_file
     * @return   mixed
     */
    protected function callFetch($template_file)
    {
        $filePath = SMARTY_TEMPLATE_PATH . $template_file;
        $fetchFile = $template_file;
        if (is_file($filePath)) {
            $tpl = $this->view->fetch($fetchFile, md5($fetchFile), md5($fetchFile));
        } else {
            $tpl = $template_file;
        }
        $response = Application::getApp()->response;
        $response->header('Content-Type', $this->content_type . '; charset=utf-8');
        // 分段返回数据,2M左右一段
        $p = 0;
        $size = $this->write_size;
        while ($data = mb_substr($tpl, $p++ * $size, $size, 'utf-8')) {
            $response->write($data);
        }
    }

    /**
     * fetch 渲染输出模板
     * @param string $template_file
     * @return
     */
    public function fetch($template_file = null)
    {
        $this->display($template_file);
    }

    /**
     * 私有或者受保护的属性赋值时会自动调用
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $this->{$name} = $value;
        return;
    }

    /**
     * 获取私有或者受保护的属性赋值时会自动调用
     * @param string $name
     * @return    mixed
     */
    public function __get($name)
    {
        return $this->{$name};
    }

}