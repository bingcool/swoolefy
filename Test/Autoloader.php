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

/**
 * 应用命名空间自动加载模板（create 时复制到 APP_PATH/Autoloader.php，并替换 "App"）。
 *
 * 类名：\{AppName}\Autoloader（与业务根命名空间一致，避免多应用全局 class Autoloader 冲突）
 * 路径：{START_DIR_ROOT}/{AppName}/...
 * 可被多次 include。
 *
 * 注意：本文件仅作模板；占位符 App 在复制到应用目录时替换为真实应用名。
 */
namespace Test;

if (!class_exists(__NAMESPACE__ . '\\Autoloader', false)) {
    class Autoloader
    {
        /** @var string|null */
        private static $baseDirectory = null;

        /** @var list<string> */
        private static $rootNamespace = ['Test'];

        /** @var array<string, true> */
        private static $classMapNamespace = [];

        /** @var array<string, int> className => coroutine id currently requiring the file */
        private static $loading = [];

        /** @var bool */
        private static $registered = false;

        /**
         * PSR-4 风格自动加载：把 App\Foo\Bar 映射为 {START_DIR_ROOT}/App/Foo/Bar.php。
         * 仅在 class / interface / trait / enum 真正定义成功后写入 classMap，避免失败后本 Worker 不再重试。
         * 同一 Worker 内并发协程加载同一类时，后来者等待，避免 require 竞态。
         *
         * @param string $className 完整类名（含命名空间）
         */
        public static function autoload($className): void
        {
            if (self::isDefined($className)) {
                self::$classMapNamespace[$className] = true;
                return;
            }

            $cid = self::coroutineId();
            if ($cid >= 0 && isset(self::$loading[$className])) {
                if (self::$loading[$className] === $cid) {
                    return;
                }
                self::waitUntilLoaded($className);
                return;
            }

            if ($cid >= 0) {
                self::$loading[$className] = $cid;
            }

            try {
                self::loadFromFile($className);
                if (self::isDefined($className)) {
                    self::$classMapNamespace[$className] = true;
                }
            } finally {
                unset(self::$loading[$className]);
            }
        }

        /**
         * Worker 接请求前预加载 Model 与各模块 Entity，把最容易在请求里首次用到的类送进进程内存。
         * Controller / Service / DTO 仍走懒加载；协程竞态由 autoload 锁处理。
         * 不要全量 require App/：文件数线性变慢，且每个 Worker 都要付一遍。
         */
        public static function preloadAppClasses(): void
        {
            $appPath = defined('APP_PATH')
                ? APP_PATH
                : (self::baseDirectory() . DIRECTORY_SEPARATOR . 'App');
            if (!is_dir($appPath)) {
                return;
            }

            $files = self::collectPreloadPhpFiles($appPath);
            sort($files, SORT_STRING);

            foreach ($files as $filepath) {
                require_once $filepath;
                $className = self::classNameFromFile($filepath);
                if ($className !== null && self::isDefined($className)) {
                    self::$classMapNamespace[$className] = true;
                }
            }
        }

        /**
         * 向 spl_autoload 注册本加载器，进程内只注册一次。
         *
         * @param bool $prepend true 时插到队列最前，优先于 Composer 等其他 autoload
         */
        public static function register($prepend = false): void
        {
            if (self::$registered) {
                return;
            }
            self::$registered = true;

            if (!function_exists('__autoload')) {
                spl_autoload_register([self::class, 'autoload'], true, $prepend);
            } else {
                trigger_error(
                    'spl_autoload_register() which will bypass your __autoload() and may break your autoloading',
                    E_USER_WARNING,
                );
            }
        }

        /**
         * 按根命名空间把类名拼成磁盘路径并 require_once。
         * is_file 失败时先 clearstatcache 再试一次，减轻 Alpine overlayfs / realpath 缓存误判。
         *
         * @param string $className 完整类名（含命名空间）
         */
        private static function loadFromFile(string $className): void
        {
            foreach (self::$rootNamespace as $namespace) {
                // 精确前缀：Foo 不匹配 Foobar\
                if ($className !== $namespace && !str_starts_with($className, $namespace . '\\')) {
                    continue;
                }

                $parts = explode('\\', $className);
                $filepath = self::baseDirectory()
                    . DIRECTORY_SEPARATOR
                    . implode(DIRECTORY_SEPARATOR, $parts)
                    . '.php';

                if (!is_file($filepath)) {
                    clearstatcache(true, $filepath);
                }

                if (is_file($filepath)) {
                    require_once $filepath;
                }

                break;
            }
        }

        /**
         * 当前协程已有其它协程在 require 同一类时，让出执行直到对方完成或超时（约 2s）。
         * 超时或对方未定义成功则自己再 load 一次，避免永久等死。
         *
         * @param string $className 完整类名（含命名空间）
         */
        private static function waitUntilLoaded(string $className): void
        {
            $spins = 0;
            while (isset(self::$loading[$className])) {
                if (self::isDefined($className)) {
                    self::$classMapNamespace[$className] = true;
                    return;
                }
                if (++$spins > 2000) {
                    break;
                }
                \Swoole\Coroutine::sleep(0.001);
            }

            if (self::isDefined($className)) {
                self::$classMapNamespace[$className] = true;
                return;
            }

            self::loadFromFile($className);
            if (self::isDefined($className)) {
                self::$classMapNamespace[$className] = true;
            }
        }

        /**
         * 判断符号是否已在当前进程定义（不触发 autoload）。
         *
         * @param string $name 完整类名 / 接口名 / trait 名 / enum 名
         * @return bool 已定义返回 true（不触发 autoload）
         */
        private static function isDefined(string $name): bool
        {
            return class_exists($name, false)
                || interface_exists($name, false)
                || trait_exists($name, false)
                || enum_exists($name, false);
        }

        /**
         * 当前协程 ID。无 Swoole 或不在协程内时返回 -1。
         *
         * @return int 协程 ID，或 -1
         */
        private static function coroutineId(): int
        {
            if (!extension_loaded('swoole')) {
                return -1;
            }

            return (int) \Swoole\Coroutine::getCid();
        }

        /**
         * 类文件根目录：优先 START_DIR_ROOT（项目根），否则退回本文件的上一级。
         * App\Foo 对应 {base}/App/Foo.php。
         *
         * @return string 绝对路径，无末尾分隔符
         */
        private static function baseDirectory(): string
        {
            if (self::$baseDirectory === null) {
                self::$baseDirectory = defined('START_DIR_ROOT')
                    ? START_DIR_ROOT
                    : dirname(__DIR__);
            }

            return self::$baseDirectory;
        }

        /**
         * 收集预加载文件：App/Model 以及 App/Module 下各模块的 Entity 目录。
         * 只扫这两个位置，避免遍历整棵业务树。
         *
         * @param string $appPath 应用目录（通常为 APP_PATH）
         * @return list<string> PHP 文件绝对路径
         */
        private static function collectPreloadPhpFiles(string $appPath): array
        {
            $files = [];
            $modelDir = $appPath . DIRECTORY_SEPARATOR . 'Model';
            if (is_dir($modelDir)) {
                $files = array_merge($files, self::collectPhpFilesIn($modelDir));
            }

            $moduleDir = $appPath . DIRECTORY_SEPARATOR . 'Module';
            if (!is_dir($moduleDir)) {
                return $files;
            }

            $modules = scandir($moduleDir);
            if ($modules === false) {
                return $files;
            }

            foreach ($modules as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $entityDir = $moduleDir . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'Entity';
                if (is_dir($entityDir)) {
                    $files = array_merge($files, self::collectPhpFilesIn($entityDir));
                }
            }

            return $files;
        }

        /**
         * 递归收集目录下全部 .php 文件。
         *
         * @param string $dir 绝对目录
         * @return list<string> PHP 文件绝对路径
         */
        private static function collectPhpFilesIn(string $dir): array
        {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
                )
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo
                    && $file->isFile()
                    && strtolower($file->getExtension()) === 'php'
                ) {
                    $files[] = $file->getPathname();
                }
            }

            return $files;
        }

        /**
         * 由磁盘路径反推 PSR-4 类名。路径必须落在 baseDirectory 下，且以 .php 结尾。
         * 例如 {base}/App/Module/Staff/Entity/StaffUserEntity.php → App\Module\Staff\Entity\StaffUserEntity。
         *
         * @param string $filepath PHP 文件绝对路径
         * @return string|null 完整类名；路径不在根目录下或不是 .php 时返回 null
         */
        private static function classNameFromFile(string $filepath): ?string
        {
            $base = self::baseDirectory();
            $normalizedBase = rtrim(str_replace('\\', '/', $base), '/') . '/';
            $normalizedFile = str_replace('\\', '/', $filepath);
            if (!str_starts_with($normalizedFile, $normalizedBase)) {
                return null;
            }

            $relative = substr($normalizedFile, strlen($normalizedBase));
            if (!str_ends_with($relative, '.php')) {
                return null;
            }

            return str_replace('/', '\\', substr($relative, 0, -4));
        }
    }

    Autoloader::register();
}

// cli.php 定义 APP_PATH 后再 include 本文件时加载业务常量
if (defined('APP_PATH') && is_file(APP_PATH . '/Config/constants.php')) {
    include_once APP_PATH . '/Config/constants.php';
}
