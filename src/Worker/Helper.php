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
                if ($param->getType()->getName() == 'array') {
                    $params[$name] = (array)$params[$name];
                } elseif (is_array($params[$name])) {
                    $isValid = false;
                } elseif (
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
            $msg = "【Error】Missing init() method cli parameters of name : " . implode(', ', $missing);
            throw new InvalidArgumentException($msg);
        }

        return [$method, $args];
    }

    /**
     * @param string $name
     * @return array|string|false
     */
    public static function getCliParams(string $name = '')
    {
        if ($name) {
            $value = @getenv($name);
            return $value !== false ? $value : null;
        } else {
            $cliParams = getenv('WORKER_CLI_PARAMS') ? json_decode(getenv('WORKER_CLI_PARAMS'), true) : [];
            $params = [];
            foreach ($cliParams as $paramName) {
                $value = @getenv($paramName);
                if ($value !== false) {
                    $params[$paramName] = $value;
                }
            }
            return $params;
        }
    }

    /**
     * @param bool $format_flag
     * @param bool $real_usage
     * @return float|int|string
     */
    public static function getMemoryUsage(bool $format_flag = true, bool $real_usage = true)
    {
        $memoryNum = memory_get_usage($real_usage);
        $format = 'bytes';
        if ($format_flag) {
            if ($memoryNum > 0 && $memoryNum < 1024) {
                return number_format($memoryNum) . ' ' . $format;
            }
            if ($memoryNum >= 1024 && $memoryNum < pow(1024, 2)) {
                $p = 1;
                $format = 'KB';
            }
            if ($memoryNum >= pow(1024, 2) && $memoryNum < pow(1024, 3)) {
                $p = 2;
                $format = 'MB';
            }
            if ($memoryNum >= pow(1024, 3) && $memoryNum < pow(1024, 4)) {
                $p = 3;
                $format = 'GB';
            }
            if ($memoryNum >= pow(1024, 4) && $memoryNum < pow(1024, 5)) {
                $p = 3;
                $format = 'TB';
            }

            $memoryNum /= pow(1024, $p);
            $memoryNum = number_format($memoryNum, 3) . ' ' . $format;
        }

        return $memoryNum;
    }
}
