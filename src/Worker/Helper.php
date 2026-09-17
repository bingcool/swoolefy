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

namespace Swoolefy\Worker;

use InvalidArgumentException;

class Helper
{
    /**
     * @param object $instance
     * @param string $action
     * @param array $params
     * @return array
     * @throws \ReflectionException
     */
    public static function parseActionParams($instance, string $action, array $params)
    {
        $method = new \ReflectionMethod($instance, $action);
        $args = $missing = $actionParams = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $params)) {
                $isValid = true;
                if ($param->hasType() && $param->getType()->getName() == 'array') {
                    $params[$name] = (array)$params[$name];
                } else if (is_array($params[$name])) {
                    $isValid = false;
                } else if (
                    ($type = $param->getType()) !== null &&
                    $type->isBuiltin() &&
                    ($params[$name] !== null || !$type->allowsNull())
                ) {
                    $typeName = $type->getName() ?? (string)$type;
                    switch ($typeName) {
                        case 'int':
                            $params[$name] = filter_var($params[$name], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                            break;
                        case 'float':
                            $params[$name] = filter_var($params[$name], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                            break;
                    }
                    if ($params[$name] === null) {
                        $isValid = false;
                    }
                }

                if (!$isValid) {
                    throw new InvalidArgumentException("Cli Received invalid parameter of {$name}");
                }
                $args[] = $actionParams[$name] = $params[$name];
                unset($params[$name]);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $actionParams[$name] = $param->getDefaultValue();
            } else {
                $missing[] = '--' . $name;
            }
        }

        if (!empty($missing)) {
            $msg = "Missing init() method cli parameters of name : " . implode(', ', $missing);
            throw new InvalidArgumentException($msg);
        }

        return [$method, $args];
    }

    /**
     * @param string $name
     * @return array|string|false|null
     */
    public static function getCliParams(string $name = '')
    {
        if ($name) {
            $value = @getenv($name);
            return $value !== false ? $value : null;
        } else {
            $cliParams = getenv('ENV_CLI_PARAMS');
            if(!empty($cliParams)) {
                $cliParams = json_decode($cliParams, true);
            }else {
                $cliParams = [];
            }
            return $cliParams;
        }
    }

    /**
     * 从 argv 读取 --name=value（daemon.php 定义常量时 ENV_CLI_PARAMS 尚未写入）。
     *
     * @param list<string>|null $argv
     */
    public static function parseCliOptionFromArgv(string $name, ?array $argv = null): ?string
    {
        $argv ??= $_SERVER['argv'] ?? [];
        $prefix = '--' . $name . '=';
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function parseGroupNames(?string $groupParam): array
    {
        if (!is_string($groupParam) || trim($groupParam) === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $groupParam) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Daemon 分组实例后缀，用于 WORKER_SERVICE_NAME / PID 目录隔离。
     *
     * `--group=group_2,group_1` 与 `--group=group_1,group_2` 得到相同后缀。
     *
     * @param list<string>|null $argv
     */
    public static function daemonGroupInstanceSuffix(?array $argv = null): string
    {
        $names = self::parseGroupNames(self::parseCliOptionFromArgv('group', $argv));
        if ($names === []) {
            return '';
        }

        sort($names);
        $tokens = [];
        foreach ($names as $name) {
            $tokens[] = self::sanitizeGroupToken($name);
        }

        return implode('+', $tokens);
    }

    /**
     * 重启时需要带回的 CLI 选项（ArrayInput 格式）。
     *
     * @return array<string, string>
     */
    public static function workerRestartInputOptions(): array
    {
        $params = self::getCliParams();
        if (!is_array($params)) {
            $params = [];
        }

        $options = [];
        foreach (['group', 'only'] as $name) {
            $value = $params[$name] ?? getenv($name);
            if ($value === false || $value === null || $value === '') {
                continue;
            }
            $options['--' . $name] = (string) $value;
        }

        return $options;
    }

    /**
     * 重启外部命令附加参数，例如 `--group=group_1 --only=foo`。
     */
    public static function workerRestartCliSuffix(): string
    {
        $parts = [];
        foreach (self::workerRestartInputOptions() as $option => $value) {
            $parts[] = $option . '=' . escapeshellarg($value);
        }

        return implode(' ', $parts);
    }

    private static function sanitizeGroupToken(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $name) ?? '';

        return $safe !== '' ? $safe : substr(hash('sha256', $name), 0, 8);
    }

    /**
     * @param bool $formatFlag
     * @param bool $realUsage
     * @return float|int|string
     */
    public static function getMemoryUsage(bool $formatFlag = true, bool $realUsage = true)
    {
        return \Swoolefy\Util\Helper::getMemoryUsage($formatFlag, $realUsage);
    }
}
