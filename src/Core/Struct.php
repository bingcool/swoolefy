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

use stdClass;

class Struct
{

    /**
     * @var stdClass
     */
    protected $stdClass;

    /**
     * __construct
     */
    public function __construct()
    {
        $this->stdClass = new stdClass();
    }

    /**
     * get property value
     * @param string $property
     * @param mixed $default
     * @return string
     */
    public function get($property, $default = null)
    {
        if (isset($this->stdClass->{$property})) {
            return $this->stdClass->{$property};
        }
        return $default;
    }

    /**
     * getProperties
     * @param bool $public
     * @return array
     */
    public function getProperties(bool $public = true)
    {
        $vars = get_object_vars($this->stdClass);
        if ($public) {
            foreach ($vars as $k => $v) {
                if ('_' == substr($k, 0, 1)) {
                    unset($vars[$k]);
                }
            }
        }
        return $vars;
    }

    /**
     * set property value
     * @param string $property
     * @param mixed $value
     * @return mixed
     */
    public function set($property, $value, bool $replace = false)
    {
        if (!isset($this->stdClass->{$property}) || $replace) {
            $this->stdClass->{$property} = $value;
        }
        return true;
    }

    /**
     * batch setProperties
     * @param array $properties
     * @return void
     */
    public function setProperties($properties)
    {
        $properties = (array)$properties;
        if (is_array($properties)) {
            foreach ($properties as $k => $v) {
                $this->stdClass->$k = $v;
            }
        }
    }

    /**
     * getPublicProperties
     * @return array
     */
    public function getPublicProperties()
    {
        return $this->getProperties(true);
    }

    /**
     * @param $property
     * @param $value
     * @return mixed
     */
    public function __set($property, $value)
    {
        return $this->set($property, $value, true);
    }

    /**
     * @param $property
     * @return string
     */
    public function __get($property)
    {
        return $this->get($property, $default = null);
    }

}