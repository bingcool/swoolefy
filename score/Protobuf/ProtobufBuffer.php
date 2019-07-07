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
		$props = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE);
		
		foreach($props as $prop) {
		    $prop->setAccessible(true);
		    $key =$prop->getName();
		    $property = self::LetterToBiger($key);
		    $method = "get".$property;   
		    if($reflect->hasMethod($method)) {
		    	$value = $obj->{$method}();
		    	$value = self::repeatedOrMessageClass($value);
		    	$array[$key] = $value;
		    }else {
		    	continue;
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
		$props = $reflect->getProperties(\ReflectionProperty::IS_PRIVATE);
		
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
	protected function repeatedOrMessageClass($value) {
    	if(is_object($value)) {
		    	if($value instanceof \Google\Protobuf\Internal\RepeatedField) {
		    		$new_value = [];
		    		foreach($value as $obj) {
		    			if($obj instanceof \Google\Protobuf\Internal\Message) {
		    				$value = self::toArray($obj);
		    			}else if($obj instanceof \Google\Protobuf\Internal\RepeatedField) {
		    				self::repeatedOrMessageClass($obj);
		    			}
		    			$new_value[] = $value;
		    		}
		    		$value = $new_value;
		    		unset($new_value);
		    	}else if($value instanceof \Google\Protobuf\Internal\Message) {
    				$value = self::toArray($value);
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