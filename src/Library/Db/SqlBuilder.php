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
    static $preparePrefix = ':SW_PREPARE';
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

    public static function buildMinRange($alias, $field, $min, &$sql, &$params, bool $include = true)
    {
        if(is_null($min) || $min === '')
        {
            $min = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}{$field} >= :min_{$field}";
        }else
        {
            $sql .= " and {$alias}{$field} > :min_{$field}";
        }

        $params[":min_{$field}"] = $min;
    }

    public static function buildMaxRange($alias, $field, $max, &$sql, &$params, bool $include = true)
    {
        if(is_null($max) || $max === '')
        {
            $max = 0;
        }

        if($include)
        {
            $sql .= " and {$alias}{$field} <= :max_{$field}";
        }else
        {
            $sql .= " and {$alias}{$field} < :max_{$field}";
        }

        $params[":max_{$field}"] = $max;
    }

    public static function buildLike($alias, $field, $keyword, &$sql, &$params, $operator = 'AND')
    {
        $sql .= " $operator {$alias}{$field} like {$keyword}";
    }

    public static function buildOrderBy($alias, $field, $rank, &$sql, &$params)
    {
        if(in_array(strtolower($rank),['asc', 'desc']))
        {
            $sql .= " order by {$alias}{$field}";
        }
    }

    public static function buildGroupBy($alias, $field, &$sql, &$params)
    {
        $sql .= " group by {$alias}{$field}";
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
