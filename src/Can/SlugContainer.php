<?php

namespace jjharr\Can;

use Illuminate\Database\Query\Builder;

class SlugContainer {

	public static $validationCharsets = [
		'slug'        => 'a-z_\\-',
		'name'        => 'a-zA-Z_\\- ',
		'description' => "a-zA-Z_.,\\- '"
	];

	protected $raw;

	protected $parsed;

	public function __construct($raw)
	{
		$this->raw = $raw;
	}

	/*
	 * TODO Should have a separate validator...
	 * TODO - need ability to detect and allow/disallow wildcards
	 */
	public static function validateOrDie($str, $strName, $placeholder = '')
	{
		if ( $placeholder === '' )
		{
			$placeholder = $strName;
		}

		$charset = self::$validationCharsets[$strName];
		if ( ! preg_match('/[' . $charset . ']/', $str) )
		{
			throw new CanException("The allowed characters for $placeholder must match $charset");
		}
		else
		{
			if ( count($str) > 254 )
			{
				throw new CanException("$placeholder must be less than 255 characters");
			}
		}

		return true;
	}

	/*
	 * Use cases :
	 *
	 * to create, we accept only or, no wildcard
	 *
	 * to fetch roles/permissions, we need just or, wildcard & not
	 *
	 * for fetching permissions, should we accept both or/and. What
	 * about with/without wildcards? Let's start with just OR and partial.
	 * Can grow beyond that as needed.
	 */

	public function hasFullyQualified()
	{
		return count($this->getFullyQualified()) > 0;
	}

	public function hasPartiallyQualified()
	{
		return count($this->getPartiallyQualified()) > 0;
	}

	public function getPartiallyQualified()
	{
		if(empty($this->parsed))
		{
			$this->parse();
		}

		return $this->parsed['partiallyQualified'];
	}

	public function getFullyQualified()
	{
		if(empty($this->parsed))
		{
			$this->parse();
		}

		return $this->parsed['fullyQualified'];
	}

	public function buildSlugQuery(Builder $query, $slugColumn='slug')
	{
		if(!($this->hasFullyQualified() || $this->hasPartiallyQualified()))
		{
			return [];
		}

		$fullyQualified = $this->getFullyQualified();
		if(count($fullyQualified) > 0)
		{
			$query = $query->whereIn($slugColumn, $this->getFullyQualified());
		}

		$partiallyQualified = $this->getPartiallyQualified();
		if(count($partiallyQualified) > 0)
		{
			// set the first conditional based on whether we had any fully qualified or not
			if(count($fullyQualified) > 0)
				$query = $query->orWhere($slugColumn,'like',array_shift($partiallyQualified));
			else
				$query = $query->where($slugColumn,'like',array_shift($partiallyQualified));

			// then set any others
			for($i=0; $i<count($partiallyQualified); $i++)
			{
				$query = $query->orWhere($slugColumn,'like',$partiallyQualified[$i]);
			}
		}

		return $query;
	}

	protected function parse()
	{
		$this->parsed = $this->segregateSlugsByType(
			$this->expandSlugArgs($this->raw)
		);
	}

	/**
	 * Parse slug arguments into something we can query with. Handles array,
	 * pipe-separated strings, and wildcards. Does NOT handle comma-separated
	 * types (those are only for has() and getRoleBy() which does not use this method).
	 * fixme - how to deal with AND queries for permission checking? Reuseable or?
	 *
	 * @param mixed $attr
	 * @param bool $allow_wildcard
	 *
	 * @return array
	 */
	protected function expandSlugArgs($attr, $allow_wildcard=true){

		if(is_array($attr))
		{
			$arr = $attr;
		} else if(strpos($attr, '|'))
		{
			$arr = explode('|', $attr);
		} else {
			$arr = [$attr];
		}

		if(!$allow_wildcard)
		{
			$arr = array_filter($arr, function($e) {
				return strpos('*', $e) !== false;
			});
		}

		return array_unique($arr, SORT_STRING);
	}

	/**
	 * Given a list of slugs, divides them into fully (without wildcard) and
	 * partially qualified (has wildcard).
	 *
	 * @param $attrs
	 *
	 * @return array
	 */
	protected function segregateSlugsByType($attrs){

		$segregated = [
			'fullyQualified' => [],
			'partiallyQualified' => []
		];

		foreach ($attrs as $a)
		{
			// only allowed position for the wildcard is the last one. Can't do a.*.c
			// replace * with % while we're here.
			if(substr($a,-1) === '*')
				$segregated['partiallyQualified'][] = str_replace('*', '%', $a);
			else
				$segregated['fullyQualified'][] = $a;
		}

		return $segregated;
	}

}
