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
	 * @return mixed
	 */
	public static function toArray($obj) {
		$array = [];
		if(!is_object($obj)) {
			return $array;
		}

		$reflect = new \ReflectionClass($obj);
		$props = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);
		
		foreach($props as $prop) {
		    $prop->setAccessible(true);
		    $property = $prop->getName();
		    $method = self::LetterToBiger($property);
		    $getter = 'get'.$method;
		    if($reflect->hasMethod($getter)) {
		    	$value = $obj->{$getter}();
		    	$value = self::repeatedOrMessageClass($value);
		    	if($prop->isProtected()) {
		    		if(!empty($value)) {
		    			$one_of_method = 'get'.self::LetterToBiger($value);
		    			$array[$value] = $obj->{$one_of_method}();
		    		}
		    	}else {
		    		$array[$property] = $value;
		    	}
		    }
		}

		return $array;
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
		$props = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);
		
		foreach($props as $prop) {
		    $prop->setAccessible(true);
		    $key =$prop->getName();
		    array_push($array, $key);
		}

		return $array;
	}

	/**
	 * repeatedOrMessageClass
	 * @param  mixed $value
	 * @return mixed 
	 */
	protected static function repeatedOrMessageClass($value) {
    	if(is_object($value)) {
    		if($value instanceof \Google\Protobuf\Internal\Message) {
    			$value = self::toArray($value);
    		}else if($value instanceof \Google\Protobuf\Internal\RepeatedField) {
	    		$tmp = [];
	    		foreach($value as $obj) {
	    			if($obj instanceof \Google\Protobuf\Internal\Message) {
	    				$tmp_value = self::toArray($obj);
	    			}else if($obj instanceof \Google\Protobuf\Internal\RepeatedField) {
	    				$tmp_value = self::repeatedOrMessageClass($obj);
	    			}else if(is_string($obj) || is_int($obj) || is_float($obj) || is_bool($obj) || is_double($obj)) {
	    				$tmp_value = $obj;
	    			}else {
	    				$tmp_value = $obj;
	    			}
	    			$tmp[] = $tmp_value;
	    		}
	    		$value = $tmp;
	    		unset($tmp);
	    	}else if($value instanceof \Google\Protobuf\Internal\MapField) {
	    		$tmp = [];
    			foreach($value as $key=>$obj) {
    				$tmp_value = self::repeatedOrMessageClass($obj);
    				$tmp[$key] = $tmp_value;
    			}
    			$value = $tmp;
    			unset($tmp);
	    	}
    		
	    }
	    return $value;
    }

    /**
     * LetterToBiger
     * @param string $letter
     */
	public static function LetterToBiger(string $letter = null) {
		$letter_arr = explode('_', $letter);
		$property = '';
		foreach ($letter_arr as $letter) {
			$property .= ucfirst($letter);
		}
		return $property;
	}

}