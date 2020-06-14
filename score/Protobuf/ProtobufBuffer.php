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

class ProtobufBuffer {
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

		$reflect = new \ReflectionClass($obj);
		$properties = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);
		
		foreach($properties as $propertyObj) {
            $propertyObj->setAccessible(true);
		    $property = $propertyObj->getName();
		    $methodName = self::LetterToBiger($property);
		    $getter = 'get'.$methodName;
		    if($reflect->hasMethod($getter)) {
		    	$value = $obj->{$getter}();
		    	$value = self::repeatedOrMessageClass($value);
		    	if($propertyObj->isProtected()) {
		    		if(!empty($value)) {
		    			$oneOfMethod = 'get'.self::LetterToBiger($value);
		    			$array[$value] = $obj->{$oneOfMethod}();
		    		}
		    	}else {
		    		$array[$property] = $value;
		    	}
		    }
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
     * getFileds
     * @param  mixed $obj
     * @return array
     */
    public static function getObjFileds($obj, ...$args) {
        $array = [];
        if(!is_object($obj)) {
            return $array;
        }
        $reflect = new \ReflectionClass($obj);
        $properties = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);

        foreach($properties as $propertyObj) {
            $propertyObj->setAccessible(true);
            $key =$propertyObj->getName();
            array_push($array, $key);
        }

        return $array;
    }

}