<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Static functions to aid in constructing and parsing of Mongo input data.
 * @package  Util
 * @since 5.1
 * @todo Most of this logic should be INSIDE of a mongo entity class that wraps
 * a data array, instead of a static class with service functions (make it OOP)
 */
class Billrun_Utils_Mongo {
		
	/**
	 * Get a mongo date object based on a period object.
	 * @param period $period
	 * @return \MongoDate or false on failure
	 * @todo Create a period object.
	 */
	public static function getDateFromPeriod($period) {
		if(!$period) {
			Billrun_Factory::log("Invalid data!", Zend_Log::ERR);
			return null;
		}
		
		if ($period instanceof MongoDate) {
			return $period;
		}
		if (isset($period['sec'])) {
			return new MongoDate($period['sec']);
		}

		$duration = $period['duration'];
		// If this plan is unlimited.
		// TODO: Move this logic to a more generic location
		if ($duration == "UNLIMITED") {
			return new MongoDate(strtotime(self::UNLIMITED_DATE));
		}
		if (isset($period['units'])) {
			$unit = $period['units'];
		} else if (isset($period['unit'])) {
			$unit = $period['unit'];
		} else {
			$unit = 'months';
		}
		return new MongoDate(strtotime("tomorrow", strtotime("+ " . $duration . " " . $unit)) - 1);
	}
	
	/**
	 * Get a query to filter all out dated and still not active records.
	 * @param int|null $sec The epoch time (of now), to use for bounding the query.
	 *						if null, use time(). Null by default.
	 * @param boolean $onlyFuture - If true, the query is bounded only with the 'to'
	 *							    field, enabling to query future records.
	 *								False by default. 
	 * @return array - Array with the to and from clauses
	 */
	public static function getDateBoundQuery($sec = NULL, $onlyFuture = false) {
		$now = is_null($sec) ? time() : $sec;
		if ($onlyFuture) {
			return array(
				'to' => array(
					'$gt' => new MongoDate($now),
				),
			);
		}
		return array(
			'to' => array(
				'$gt' => new MongoDate($now),
			),
			'from' => array(
				'$lte' => new MongoDate($now),
			)
		);
	}
	
	/**
	 * Get a value from an array by a mongo format key, seperated with dots.
	 * @param array $array - Array to get value of.
	 * @param string $key - Dot seperated key.
	 */
	public static function getValueByMongoIndex($array, $key) {
		if(!is_string($key)) {
			return null;
		}
		
		$value = $array;
		
		// Explode the keys.
		$keys = explode(".", $key);
		foreach ($keys as $innerKey) {
			if(!isset($value[$innerKey])) {
				return null;
			}
			
			$value = $value[$innerKey];
		}
		
		return $value;
	}
	
	/**
	 * Set a value to an array by a mongo format key, seperated with dots.
	 * @param mixed $value - Value to set
	 * @param array &$array - Array to set value to, passed by reference.
	 * @param string $key - Dot seperated key.
	 * @return boolean - True if successful.
	 */
	public static function setValueByMongoIndex($value, &$array, $key) {
		if(!is_string($key)) {
			return false;
		}
		
		$result = &$array;
		$keys = explode('.', $key);
		foreach ($keys as $innerKey) {
			$result = &$result[$innerKey];
		}

		$result = $value;
		
		return true;
	}
	
	/**
	 * Coverts a $seperator seperated array to an haierchy tree
	 * @param string $array - Input string
	 * @param string $seperator 
	 * @param mixed $toSet - Value to be set to inner level of array
	 * @return array
	 */
	public static function mongoArrayToPHPArray($array, $seperator, $toSet) {
		if(!is_string($array)) {
			return null;
		}
		
		$parts = explode($seperator, $array);
		$result = array();
		$previous = null;
		$iter = &$result;
		foreach ($parts as $value) {
			if($previous !== null) {
				$iter[$previous] = array($value => $toSet);
				$iter = &$iter[$previous];
			}
			$previous = $value;
		}
		
		return $result;
	}
	
	/**
	 * Coverts a $seperator seperated array to an haierchy tree
	 * @param string $array - Input string
	 * @param string $seperator 
	 * @param mixed $toSet - Value to be set to inner level of array
	 * @return array
	 */
	public static function mongoArrayToInvalidFieldsArray($array, $seperator) {
		if(!is_string($array)) {
			return null;
		}
		
		$parts = explode($seperator, $array);
		$result = array();
		$previous = null;
		$iter = &$result;
		foreach ($parts as $value) {
			if($previous !== null) {
				$iter[$previous] = new Billrun_DataTypes_InvalidField($value);
				$iter = &$iter[$previous];
			}
			$previous = $value;
		}
		
		return $result;
	}
	
	/**
	 * convert assoc array to MongoDB query
	 * 
	 * @param array $array the array to convert
	 * @return array the MongoDB array conversion
	 * 
	 * @todo move to Mongodloid
	 */
	public static function arrayToMongoQuery($array) {
		$query = array();
		foreach ($array as $key => $val) {
			if (!is_array($val) || strpos($key, '$') === 0) {
				$query[$key] = $val;
				continue;
			}
			foreach (self::arrayToMongoQuery($val) as $subKey => $subValue) {
				if (strpos($subKey, '$') === 0) {
					$query[$key][$subKey] = $subValue;
				} else {
					$query[$key . "." . $subKey] = $subValue;
				}
			}
		}
		return $query;
	}
	
	/**
	 * convert all MongoDate objects in the data received into ISO dates
	 * 
	 * @param mixed $data
	 * @return mixed $data with ISO dates
	 */
	public static function convertMongoDatesToReadable($data) {
		if ($data instanceof MongoDate) {
			return date(DATE_ISO8601, $data->sec);
		}
		if (!is_array($data)) {
			return $data;
		}
		foreach ($data as $key => $value) {
			$data[$key] = self::convertMongoDatesToReadable($value);
		}
		return $data;
	}
	
	/**
	 * Change the times of a mongo record
	 * 
	 * @param array $row - Record to change the times of.
	 * @param array $fields - date time fields array list
	 * @param string $format - format datetime (based on php date function)
	 * 
	 * @return The record with translated time.
	 */
	public static function convertRecordMongoDatetimeFields($record, array $fields = array('from', 'to'), $format = DATE_ISO8601) {
		foreach ($fields as $timeField) {
			if (isset($record[$timeField]->sec)) {
				$record[$timeField] = date($format, $record[$timeField]->sec);
			}
		}

		return $record;
	}

	/**
	 * Change the times of a mongo record
	 * 
	 * @param array $row - Record to change the times of.
	 * @param array $fields - date time fields array list
	 * @param string $format - format datetime (based on php date function)
	 * 
	 * @return The record with translated time.
	 */
	public static function recursiveConvertRecordMongoDatetimeFields($record, array $fields = array('from', 'to'), $format = DATE_ISO8601) {
		foreach ($record as $key => $subRecord) {
			if (is_array($subRecord)) {
				$record[$key] = self::recursiveConvertRecordMongoDatetimeFields($subRecord, $fields, $format);
			}
		}

		return self::convertRecordMongoDatetimeFields($record, $fields, $format);
	}
	
	/**
	 * Convert the date values in a query to Mongo format
	 * @param array $arr - Arr to translate its values.
	 */
	public static function convertQueryMongoDates(&$arr) {
		$ISODatePattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/';
		foreach ($arr as &$value) {
			if (is_array($value)) {
				self::convertQueryMongoDates($value);
			} else if (preg_match($ISODatePattern, $value)) {
				$value = new MongoDate(strtotime($value));
			}
		}
	}
	
	/**
	 * Get an overlapping dates query
	 * @param type $searchKeys - Array, must include the from and to fields.
	 * @param type $new
	 * @return string|\MongoId
	 */
	public static function getOverlappingDatesQuery($searchKeys, $new = true) {
		if(!isset($searchKeys['from'], $searchKeys['to'])) {
			return 'missing date keys';
		}
		
		if(empty($searchKeys)) {
			return "Empty search keys";
		}
		if ($searchKeys['from'] instanceof MongoDate) {
			$from_date = $searchKeys['from'];
		} else {
			$from_date = new MongoDate(strtotime($searchKeys['from']));
		}
		if (!$from_date) {
			return "date error 1";
		}
		unset($searchKeys['from']);
		if ($searchKeys['to'] instanceof MongoDate) {
			$to_date = $searchKeys['to'];
		} else {
			$to_date = new MongoDate(strtotime($searchKeys['to']));
		}
		if (!$to_date) {
			return "date error 2";
		}
		unset($searchKeys['to']);
		
		if(!$new && !isset($searchKeys['_id'])) {
			return "id error 1";
		}
		
 		if(!$new) {
			$id = self::getId($searchKeys);
			if(is_string($id)) {
				return $id;
			}
			unset($searchKeys['_id']);
		}
		
		$ret = array();
		foreach ($searchKeys as $key => $pair) {
			$ret[$key] = $pair;
		}
		$ret['$or'] = array(
				array('from' => array(
					'$gte' => $from_date,
					'$lt' => $to_date,
				)),
				array('to' => array(
					'$gt' => $from_date,
					'$lte' => $to_date,
				))
			);
		if (!$new) {
			$ret['_id'] = array('$ne' => $id);
		}
		return $ret;
	}
	
	protected static function getId($searchKeys) {
		$id = isset($searchKeys['_id']) ? ($searchKeys['_id']) : (NULL);
		if (!$id) {
			return "id error 2";			
		}
		if($id instanceof Mongodloid_Id) {
			return $id->getMongoID();
		}
		return $id;
	}
}
