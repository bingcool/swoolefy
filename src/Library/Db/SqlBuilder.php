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

namespace Swoolefy\Library\Db;

class SqlBuilder
{
    static $preparePrefix = ':XM_PREPARE';
    static $paramCount = 0;

    public static function buildMultiWhere($alias, array $conditions, &$sql, &$params, $operator = 'AND')
    {
        foreach ($conditions as $field => $value) {
            self::buildWhere($alias, $field, $value, $sql, $params, $operator);
        }
    }

    public static function buildWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if (!is_null($value)) {
            if (is_array($value)) {
                if (count($value) > 0) {
                    if (count($value) > 1) {
                        $prepareParams= self::buildInWhere($value,$params);
                        $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";
                        return;
                    } else {
                        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
                        $params["{$prepareField}"] = current($value);
                    }
                }
            } else {
                $sql .= " {$operator} {$alias}{$field}={$prepareField}";
                $params["{$prepareField}"] = $value;
            }
        }
    }

    public static function buildIntWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if(is_null($value))
            return ;

        if(is_array($value))
        {
            $count = count($value);
            if( $count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }


        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }

    public static function buildNotIntWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if( is_null($value) )
            return ;

        if( is_array($value) )
        {
            $count = count($value);
            if($count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}{$field} NOT IN (".implode(',',$prepareParams).")";

                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}{$field} !={$prepareField}";
        $params["{$prepareField}"] = $value;
    }


    public static function buildStringWhere($alias, $field, $value, &$sql, &$params, $operator = 'AND')
    {
        $prepareField = static::getPrepareField($field);
        if(is_null($value))
            return ;

        if(is_array($value))
        {
            $count = count($value);
            if($count ==0)
                return;

            if($count >1)
            {
                $prepareParams= self::buildInWhere($value,$params);
                $sql .= " {$operator} {$alias}{$field} IN (".implode(',',$prepareParams).")";
                return;
            }

            $value = current($value);
        }

        $sql .= " {$operator} {$alias}{$field}={$prepareField}";
        $params["{$prepareField}"] = $value;
    }


    public static function buildDateRange($alias, $field, $startTime, $endTime, &$sql, &$params)
    {
        if ($startTime) {
            $sql .= " and {$alias}{$field} >= :begin_{$field}";
            $params[":begin_{$field}"] = strlen($startTime) == 10 ? $startTime . ' 00:00:00' : $startTime;
        }
        if ($endTime) {
            $sql .= " and {$alias}{$field} <= :end_{$field}";
            $params[":end_{$field}"] = strlen($endTime) == 10 ? $endTime . ' 23:59:59' : $endTime;
        }
    }

    private static function buildInWhere($values, &$params)
    {
        $prepareParams = [];
        foreach ($values as $item) {
            $key = static::$preparePrefix.'_'.static::$paramCount;
            $prepareParams[] = $key;
            $params[$key] = $item;
            static::$paramCount++;
        }
        return $prepareParams;
    }

    private static function getPrepareField($field)
    {
        $key = static::$preparePrefix.'_'.$field.'_'.static::$paramCount;
        static::$paramCount++;
        return $key;
    }

    public static function buildInsert($table, $data)
    {
        return self::buildMultiInsert($table, [$data]);
    }

    public static function buildMultiInsert(string $table, array $dataSet)
    {
        $fields = [];
        $paramsKeys = [];
        $params = [];
        foreach ($dataSet as $index => $data) {
            foreach ($data as $k => $v) {
                $fields[$k] = $k;
                $paramsKeys[$index][] = $paramKey = ":{$k}_{$index}";
                $params[$paramKey] = $v;
            }
            $paramsKeys[$index] = "(" . implode(',', $paramsKeys[$index]) . ")";
        }

        $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES " . implode(',', $paramsKeys);

        return [$sql, $params];
    }
}
