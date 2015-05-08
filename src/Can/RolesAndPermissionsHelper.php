<?php

namespace jjharr\Can;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait RolesAndPermissionsHelper {

	/*
	 * TODO
	 * Tests (for both permissions and roles) :
	 * - basic
	 * - validation: length, chars
	 * - different formats, mixed formats
	 *
	 * add Role::copy for apps that have per-company roles with default roles
	 * that can be customized (ala Atlassian).
	 */

	public $slug;
	public $name;
	public $description;

	// depart from naming convention for easy initialization from db query
	protected $updated_at;
	protected $created_at;

	public function getUpdatedAt()
	{
		return $this->updated_at;
	}

	public function getCreatedAt()
	{
		return $this->created_at;
	}

	protected function isUnique($slug)
	{
		return 0 === DB::table(self::$table)->where('slug', $slug)->count();
	}

	public function __toString()
	{
		/** @noinspection MagicMethodsValidityInspection */
		return $this->slug;
	}

	/////////////////////////////
	// Static operations
	/////////////////////////////

	//////////// Create /////////

	/**
	 * Create one or more permissions
	 *
	 * @param array $items An array of ['slug' =>, 'display' =>], or an array
	 *                           of arrays in this format.
	 */
	public static function create(array $items)
	{
		if(count($items) === 0)
		{
			return [];
		}

		$normalized = static::normalize($items);

		// check for unique before insertion so we can return a clear error
		// instead of an SQL exception. Only do this on create.
		if(!static::isSlugUnique(array_column($normalized, 'slug')))
		{
			throw new CanException('Cannot create for '.__CLASS__.', found duplicate slugs in database.');
		}

		if(count($normalized) == 1)
		{
			return static::createSingle($normalized);
		} else
		{
			return static::createBulk($normalized);
		}
	}

	protected static function isSlugUnique(array $slugs)
	{
		return 0 === DB::table(self::$table)->whereIn('slug', $slugs)->count();
	}

	protected static function createSingle($args)
	{
		DB::table(self::$table)->insert(static::normalize($args));
		return new static(static::toArray($args));
	}

	protected static function createBulk(array $items)
	{
		DB::table(self::$table)->insert($items);

		$inserted = DB::table(self::$table)
			->whereIn('slug', array_column($items, 'slug'))
			->get();

		$hydrated = [];
		foreach ($inserted as $item)
		{
			$hydrated[] = new static(static::toCanArray($item));
		}

		return $hydrated;
	}

	private static function normalize($args)
	{
		if(self::isShorthandFormat($args))
		{
			$expanded = [];
			foreach($args as $slug)
			{
				$expanded[] = [
					'slug' => $slug
				];
			}
			$args = $expanded;
		}

		$normalized = [];
		foreach($args as $item)
		{
			$normalized[] = [
				'slug' => static::slug($item),
				'name' => static::name($item),
				'description' => static::description($item)
			];
		}

		return $normalized;
	}

	private static function isShorthandFormat(array $args)
	{
		return !is_array($args[0]);
	}

	private static function slug($item)
	{
		if(!isset($item['slug']) && count($item['slug']) > 0 )
		{
			throw new CanException('\'slug\' is a required field for '.__CLASS__);
		}

		SlugContainer::validateOrDie($item['slug'], 'slug');
		return $item['slug'];
	}

	private static function name($item)
	{
		$name = '';

		if(isset($item['name']))
		{
			$name = $item['name'];
		} else {
			$parts = explode('.', $item['slug']);
			if(count($parts) == 1)
			{
				$name = ucwords( str_replace('_', ' ',$parts[0]));
			} else {
				// TODO - make this smart from config, resource first/last, order
				foreach($parts as $part)
				{
					$name .= ucfirst($part).' ';
				}
				$name = substr($name, 0, -1);
			}
		}

		SlugContainer::validateOrDie($name, 'name');

		return $name;
	}

	private static function description($item)
	{
		if(isset($item['description']))
		{
			$description = $item['description'];
		} else {
			$description = static::name($item);
		}

		SlugContainer::validateOrDie($description, 'description');

		return $description;
	}

	//////////// Fetch /////////

	/**
	 * Return a single role or permissions using its slug as a key
	 *
	 * @param $slug
	 *
	 * @return object|null
	 * @throws CanException
	 */
	public static function single($slug)
	{
		// leave full validation for query
		if(!is_string($slug))
		{
			throw new CanException('single() requires a string argument');
		} else if(strpos($slug, '*')) {
			throw new CanException('single() does not accept wildcards (*)');
		}

		$result = static::many([$slug]);
		return count($result) > 0 ? new static(static::toCanArray($result[0])) : null;
	}

	/**
	 * Return (potentially) multiple roles or permissions using slugs as a key
	 *
	 * @param $slugs
	 *
	 * @return array
	 */
	public static function many($slugs)
	{
		$query = DB::table(self::$table);

		$container = new SlugContainer($slugs);
		$query = $container->buildSlugQuery($query);

		$hits = $query->distinct()->get();

		return self::hitsToObjects($hits);
	}

	public static function all()
	{
		$hits = DB::table(self::$table)->distinct()->get();
		return self::hitsToObjects($hits);
	}

	protected static function hitsToObjects(array $hits)
	{
		$results = [];
		foreach($hits as $hit)
		{
			$results[] = new static(static::toCanArray($hit));
		}

		return $results;
	}

	/**
	 * Builder can be configured to return either object or array. Handle both here.
	 *
	 * @param $thing
	 *
	 * @return array
	 */
	private static function toCanArray($thing)
	{
		if(is_array($thing))
			return $thing;
		else if(is_object($thing))
			return get_object_vars($thing);
		else
			throw new CanException('Got some non-object, non-array thing I can\'t handle');
	}

}
