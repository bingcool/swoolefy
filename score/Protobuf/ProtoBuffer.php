<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Protobuf;

use Google\Protobuf\Internal\DescriptorPool;

class ProtoBuffer {
	/**
	 * toArray 
	 * @param  mixed $obj
	 * @return array
	 */
	public static function toArray($obj) {
		$array = [];
		if(!is_object($obj)) {
			return $array;
		}

        $pool = DescriptorPool::getGeneratedPool();
        $desc = $pool->getDescriptorByClassName(get_class($obj));
        /** @var \Google\Protobuf\Internal\FieldDescriptor  $field */
        foreach($desc->getField() as $k=>$field) {
            $fieldName = $field->getName();
            $getter = $field->getGetter();
            if($field->getOneofIndex() !== -1) {
                /** @var \Google\Protobuf\Internal\OneofDescriptor  $oneOf */
                $oneOf = $desc->getOneofDecl()[$field->getOneofIndex()];
                $oneOfName = $oneOf->getName();
                $oneOfGetter = 'get'.self::LetterToBiger($oneOfName);
                $selectOneOfName = $obj->$oneOfGetter();
                if($fieldName != $selectOneOfName) {
                    continue;
                }
            }

            $value = $obj->$getter();

            $value = self::repeatedOrMessageClass($value);

            $array[$fieldName] = $value;
		}

		return $array;
	}

	/**
	 * repeatedOrMessageClass
	 * @param  mixed $value
	 * @return mixed 
	 */
	protected static function repeatedOrMessageClass($value) {
	    if(!is_object($value)) {
	        return $value;
        }
	    switch(true) {
            case $value instanceof \Google\Protobuf\Internal\Message :
                    $value = self::toArray($value);
                break;
            case $value instanceof \Google\Protobuf\Internal\RepeatedField :
                    $tmpData = [];
                    foreach($value as $obj) {
                        if($obj instanceof \Google\Protobuf\Internal\Message) {
                            $tmpValue = self::toArray($obj);
                        }else if($obj instanceof \Google\Protobuf\Internal\RepeatedField) {
                            $tmpValue = self::repeatedOrMessageClass($obj);
                        }else if(is_string($obj) || is_int($obj) || is_float($obj) || is_bool($obj) || is_double($obj)) {
                            $tmpValue = $obj;
                        }else {
                            $tmpValue = $obj;
                        }
                        $tmpData[] = $tmpValue;
                    }
                    $value = $tmpData;
                    unset($tmpData);
                break;
            case $value instanceof \Google\Protobuf\Internal\MapField :
                    $tmpData = [];
                    foreach($value as $key=>$obj) {
                        $tmpValue = self::repeatedOrMessageClass($obj);
                        $tmpData[$key] = $tmpValue;
                    }
                    $value = $tmpData;
                    unset($tmpData);
                break;
            default:
                break;
        }

	    return $value;
    }

    /**
     * LetterToBiger
     * @param string $letter
     * @return string
     */
	public static function LetterToBiger(string $letter = null) {
		$letterArr = explode('_', $letter);
		$property = '';
		foreach ($letterArr as $letter) {
			$property .= ucfirst($letter);
		}
		return $property;
	}

    /**
     * @param \Google\Protobuf\Internal\Message $obj
     * @param $jsonString
     * @param bool $ignore_unknown
     * @throws \Exception
     */
	public static function mergeFromJsonString(& $obj, $jsonString, $ignore_unknown = false) {
        $obj->mergeFromJsonString($jsonString, $ignore_unknown);
        return $obj;
    }

    /**
     * @param $obj
     * @return false|string
     */
    public static function serializeToJsonString($obj){
	    return json_encode(self::toArray($obj));
    }
}