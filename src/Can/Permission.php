<?php

namespace jjharr\Can;


use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Permission {
	use RolesAndPermissionsHelper;

	protected static $table = 'permissions';

	public function __construct(array $properties)
	{
		foreach($properties as $name => $value)
		{
			$this->{$name} = $value;
		}
	}

	public function users()
	{
		$userClass = Config::get('auth.model');
		$ids = $this->userIds();
		return $userClass::whereIn('id', $ids);
	}

	public static function remove($permissionSlug)
	{
		// todo - allow multiple slugs per call
		$count = DB::table(Config::get('can.permission_table'))
			->where('slug', $permissionSlug)
			->delete();

		DB::table(Config::get('can.role_permission_table'))
			->where('permissions_slug', $permissionSlug)
			->delete();

		DB::table(Config::get('can.user_permission_table'))
			->where('permissions_slug', $permissionSlug)
			->delete();

		return $count > 0;
	}

	protected function userIds()
	{
		return DB::table(Config::get('can.user_permission_table'))
			->whereIn('permissions_slug',$this->slug)
			->get('user_id');
	}

}
