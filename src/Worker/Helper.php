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
     * @param bool $formatFlag
     * @param bool $realUsage
     * @return float|int|string
     */
    public static function getMemoryUsage(bool $formatFlag = true, bool $realUsage = true)
    {
        return \Swoolefy\Util\Helper::getMemoryUsage($formatFlag, $realUsage);
    }
}
