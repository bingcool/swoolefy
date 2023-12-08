<?php


namespace Test\Scripts;

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Test\Factory;

class PhpGit extends \Swoolefy\Script\MainCliScript
{
    public function loadEnvFile()
    {
        $envRepositoryPath = dirname(START_DIR_ROOT).'/library';

        // 指定要执行的 Git 命令
        $gitCommand = "cd {$envRepositoryPath} && git checkout master && git pull";

        // 使用 exec() 函数执行 Git 命令
        exec($gitCommand, $output, $returnCode);

        // 检查命令执行结果
        if ($returnCode === 0) {
            // 文件下载成功
            var_dump('拉取成功！');

            // 复制配置文件到swoolefy根目录下
            $envRepositoryPathFile = $envRepositoryPath.'/src/Validate.php';
            include $envRepositoryPathFile;

            $startPath = START_DIR_ROOT.'/'.APP_NAME;
            $cpCommand = "cp $envRepositoryPathFile $startPath";
            exec($cpCommand, $output, $returnCode);

        } else {
            // 文件下载失败
            var_dump('拉取失败！');


            // 复制配置文件到swoolefy根目录下
            $envRepositoryPathFile = $envRepositoryPath.'/src/Validate.php';
            include $envRepositoryPathFile;

            $startPath = START_DIR_ROOT.'/'.APP_NAME;
            $cpCommand = "cp $envRepositoryPathFile $startPath";
            exec($cpCommand, $output, $returnCode);


        }
    }
}