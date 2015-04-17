<?php

namespace Can;

class Permission {
	use AttrHelper;

	protected static $table = 'permissions';

	public $id;
	public $slug;
	public $display;

	public function __construct($attr)
	{
		foreach ($attr as $k => $v)
		{
			$this->{$k} = $v;
		}
	}

	/**
	 * Create one or more permissions
	 *
	 * @param array $permissions An array of ['slug' =>, 'display' =>], or an array
	 *                           of arrays in this format.
	 */
	public static function create(array $permissions)
	{
		if(!static::isValid($permissions))
		{
			throw new \Exception('Invalid permissions passed to create');
		}

		return static::isMultiple($permissions) ?
			static::createBulk($permissions) :
			static::createSingle($permissions);
	}

	public static function get($args)
	{
		return static::fetchAttrs($args);
	}

	protected static function createSingle($args)
	{
		$id = DB::table(static::$table)->insertGetId(static::normalize($args));
		return new Permission([
			'id' => $id,
			'slug' => $args['slug'],
			'display' => $args['display']
		]);
	}

	protected static function createBulk($args)
	{
		$normalized = [];
		foreach ($args as $arg)
		{
			$normalized[] = static::normalize($arg);
		}

		$slugs = array_column($normalized, 'slug');

		DB::table(static::$table)->insert($normalized);
		$inserted = DB::table(static::$table)->whereIn('slug', $slugs)->get();

		$permissions = [];
		foreach ($inserted as $p)
		{
			$permissions[] = new Permission([
				'id' => $p->id,
				'slug' => $p->slug,
				'display' => $p->display
			]);
		}

		return $permissions;
	}

	private static function isValid($args)
	{
		return is_array($args) && count($args) > 0;
	}

	private static function isMultiple($args)
	{
		return count($args) && is_array($args[0]);
	}

	private static function normalize($args)
	{
		if(!isset($args['slug']))
		{
			throw new CanException('\'slug\' is a required field to create permissions');
		} else if(preg_match('[a-zA-Z_\-]', $args['slug'])) {
			throw new CanException('slugs may only use alphanumeric characters, dashes, or underscores');
		}

		$args['display'] = isset($args['display']) ?
			$args['display'] :
			static::displayName($args['slug']);

		return $args;
	}

	private static function displayName($slug)
	{
		return ucwords( str_replace('_', ' ',$slug) );
	}
}
