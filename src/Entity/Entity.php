<?php

namespace Metabolism\WordpressBundle\Entity;

use Metabolism\WordpressBundle\Helper\ACF;

/**
 * Class Entity
 *
 * @package Metabolism\WordpressBundle\Entity
 */
class Entity
{
	public static $remove = [
		'xfn', 'db_id', 'post_mime_type', 'ping_status', 'to_ping', 'pinged', '_edit_lock',
		'guid', 'filter', 'post_content_filtered', 'url', 'name', 'author_IP', 'agent'
	];

	public $ID;
	private $custom_fields;
	private $imported=false;

	public static $date_format = false;

	public function import( $info, $remove=false , $replace=false )
	{
		$info = self::normalize($info, $remove, $replace);

		if ( is_object($info) )
			$info = get_object_vars($info);

		if ( is_array($info) )
		{
			foreach ( $info as $key => $value )
			{
				if ( $key === '' || ord($key[0]) === 0 )
					continue;

				if ( !empty($key) && !method_exists($this, $key) )
					$this->$key = $value;
			}
		}

		$this->imported = true;
	}


	/**
	 * Return true if all fields have been loaded to the entity
	 */
	public function loaded()
	{
		return (!$this->custom_fields || $this->custom_fields->loaded()) && $this->imported;
	}


	/**
	 * Add custom fields as members of the post
	 */
	protected function addCustomFields( $id )
	{
		if( class_exists('ACF') )
		{
			$this->custom_fields = new ACF( $id );

			foreach ($this->custom_fields->get() as $name => $value )
			{
				$this->$name = $value;
			}
		}
	}


	public static function normalize($object, $remove=false, $replace=false)
	{
		if( is_object($object) )
			$object = get_object_vars($object);

		if( !is_array($object) )
			return false;

		if( isset($object['url']) )
			$object['link'] = $object['url'];

		if( isset($object['name']) and !isset($object['title']) )
			$object['title'] = $object['name'];

		if( !self::$date_format )
			self::$date_format = get_option('date_format');

		if( isset($object['post_date']) ){
			$object['post_date'] = (string) mysql2date( self::$date_format, $object['post_date']);
			$object['post_date'] = apply_filters('get_the_date', $object['post_date'], self::$date_format);
		}

		if( isset($object['post_modified']) ){
			$object['post_modified'] = (string) mysql2date( self::$date_format, $object['post_modified']);
			$object['post_modified'] = apply_filters('get_the_date', $object['post_modified'], self::$date_format);
		}

		foreach(self::$remove as $prop){

			if( isset($object[$prop]) )
				unset($object[$prop]);
		}

		if( isset($object['classes']) and count($object['classes']) )
		{
			if( empty($object['classes'][0]))
				array_shift($object['classes']);

			$object['class'] = implode(' ', $object['classes']);
		}

		if( $remove )
		{
			foreach($object as $key=>$value)
			{
				if( strpos($key, $remove) === 0 )
					unset($object[$key]);
			}
		}

		foreach($object as $key=>$value)
		{
			if($replace && strpos($key, $replace) === 0 ){

				$new_key = str_replace($replace,'', $key);

				if( !isset($object[$new_key]) or empty($object[$new_key]))
					$object[$new_key] = $value;

				unset($object[$key]);
			}
		}

		return $object;
	}
}
