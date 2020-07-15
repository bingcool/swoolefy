<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db\Concern;

/**
 * 模型数据处理
 */
trait Attribute
{
    /**
     * 数据表主键 复合主键使用数组定义
     * @var string|array
     */
    protected $pk = 'id';

    /**
     * 数据表字段信息 留空则自动获取
     * @var array
     */
    protected $schema = [];

    /**
     * 当前允许写入的字段
     * @var array
     */
    protected $field = [];

    /**
     * 字段自动类型转换
     * @var array
     */
    protected $type = [];

    /**
     * 数据表废弃字段
     * @var array
     */
    protected $disuse = [];

    /**
     * 数据表只读字段
     * @var array
     */
    protected $readonly = [];

    /**
     * 当前模型数据
     * @var array
     */
    private $data = [];

    /**
     * 原始数据
     * @var array
     */
    private $origin = [];

    /**
     * JSON数据表字段
     * @var array
     */
    protected $json = [];

    /**
     * JSON数据表字段类型
     * @var array
     */
    protected $jsonType = [];

    /**
     * JSON数据取出是否需要转换为数组
     * @var bool
     */
    protected $jsonAssoc = false;

    /**
     * 是否严格字段大小写
     * @var bool
     */
    protected $strict = true;

    /**
     * 修改器执行记录
     * @var array
     */
    private $set = [];

    /**
     * 获取模型对象的主键
     * @access public
     * @return string|array
     */
    public function getPk()
    {
        return $this->pk;
    }

    /**
     * 判断一个字段名是否为主键字段
     * @access public
     * @param  string $key 名称
     * @return bool
     */
    protected function isPk(string $key): bool
    {
        $pk = $this->getPk();

        if (is_string($pk) && $pk == $key) {
            return true;
        } elseif (is_array($pk) && in_array($key, $pk)) {
            return true;
        }

        return false;
    }

    /**
     * 获取模型对象的主键值
     * @access public
     * @return mixed
     */
    public function getPkValue()
    {
        $pk = $this->getPk();
        if (is_string($pk) && array_key_exists($pk, $this->data)) {
            return $this->data[$pk];
        }
        return;
    }

    /**
     * 设置允许写入的字段
     * @access public
     * @param  array $field 允许写入的字段
     * @return $this
     */
    public function allowField(array $field)
    {
        $this->field = $field;

        return $this;
    }

    /**
     * 获取对象原始数据 如果不存在指定字段返回null
     * @access public
     * @param  string $name 字段名 留空获取全部
     * @return mixed
     */
    public function getOrigin(string $name = null)
    {
        if (is_null($name)) {
            return $this->origin;
        }

        return array_key_exists($name, $this->origin) ? $this->origin[$name] : null;
    }

    /**
     * 获取对象原始数据(原始出表数据，没做任何formater处理) 如果不存在指定字段返回false
     * @access public
     * @param  string $fieldName 字段名 留空获取全部
     * @return mixed
     * @throws Exception
     */
    public function getData(string $fieldName = null)
    {
        if(is_null($fieldName)) {
            return $this->data;
        }
        return $this->data[$fieldName] ?? null;
    }

    /**
     * 获取变化的数据 并排除只读数据
     * @access public
     * @return array
     */
    public function getChangedData(): array
    {
        $data = $this->force ? $this->data : array_udiff_assoc($this->data, $this->origin, function ($a, $b) {
            if ((empty($a) || empty($b)) && $a !== $b) {
                return 1;
            }

            return is_object($a) || $a != $b ? 1 : 0;
        });

        // 只读字段不允许更新
        foreach ($this->readonly as $key => $field) {
            if (isset($data[$field])) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 直接设置数据对象值
     * @access public
     * @param  string $name  属性名
     * @param  mixed  $value 值
     * @return void
     */
    public function set(string $name, $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * 数据写入 类型转换
     * @access protected
     * @param  mixed        $value 值
     * @param  string|array $type  要转换的类型
     * @return mixed
     */
    protected function writeTransform($value, $type)
    {
        if (is_null($value)) {
            return;
        }

        if (is_array($type)) {
            [$type, $param] = $type;
        } elseif (strpos($type, ':')) {
            [$type, $param] = explode(':', $type, 2);
        }

        switch ($type) {
            case 'integer':
                $value = (int) $value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float) $value;
                } else {
                    $value = (float) number_format($value, (int) $param, '.', '');
                }
                break;
            case 'boolean':
                $value = (bool) $value;
                break;
            case 'timestamp':
                if (!is_numeric($value)) {
                    $value = strtotime($value);
                }
                break;
            case 'datetime':
                $value = is_numeric($value) ? $value : strtotime($value);
                $value = $this->formatDateTime('Y-m-d H:i:s.u', $value, true);
                break;
            case 'object':
                if (is_object($value)) {
                    $value = json_encode($value, JSON_FORCE_OBJECT);
                }
                break;
            case 'array':
                $value = (array) $value;
            case 'json':
                $option = !empty($param) ? (int) $param : JSON_UNESCAPED_UNICODE;
                $value  = json_encode($value, $option);
                break;
            case 'serialize':
                $value = serialize($value);
                break;
            default:
                if (is_object($value) && false !== strpos($type, '\\') && method_exists($value, '__toString')) {
                    // 对象类型
                    $value = $value->__toString();
                }
        }

        return $value;
    }

    /**
     * 数据读取 类型转换
     * @access protected
     * @param  mixed        $value 值
     * @param  string|array $type  要转换的类型
     * @return mixed
     */
    protected function readTransform($value, $type)
    {
        if (is_null($value)) {
            return;
        }

        if (is_array($type)) {
            [$type, $param] = $type;
        } elseif (strpos($type, ':')) {
            [$type, $param] = explode(':', $type, 2);
        }

        switch ($type) {
            case 'integer':
                $value = (int) $value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float) $value;
                } else {
                    $value = (float) number_format($value, (int) $param, '.', '');
                }
                break;
            case 'boolean':
                $value = (bool) $value;
                break;
            case 'timestamp':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value  = $this->formatDateTime($format, $value, true);
                }
                break;
            case 'datetime':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value  = $this->formatDateTime($format, $value);
                }
                break;
            case 'json':
                $value = json_decode($value, true);
                break;
            case 'array':
                $value = empty($value) ? [] : json_decode($value, true);
                break;
            case 'object':
                $value = empty($value) ? new \stdClass() : json_decode($value);
                break;
            case 'serialize':
                try {
                    $value = unserialize($value);
                } catch (\Exception $e) {
                    $value = null;
                }
                break;
            default:
                if (false !== strpos($type, '\\')) {
                    // 对象类型
                    $value = new $type($value);
                }
        }

        return $value;
    }
}
