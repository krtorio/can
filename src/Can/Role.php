<?php

namespace jjharr\Can;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Role {
	use RolesAndPermissionsHelper;

	// TODO - add method for clearAll()
	// TODO - add the ability to merge in permissions from another role "inherit($roleName)"

	// TODO - require Carbon in dependencies
	// TODO - standard self vs. static everywhere, esp. for $table

	protected static $table = 'roles';

	public function __construct(array $properties)
	{
		foreach($properties as $k => $v)
		{
			$this->{$k} = $v;
		}
	}

	public function attachPermissions(array $permissionSlugs)
	{
		SlugContainer::validateOrDie($this->slug, 'slug', 'The Role slug');

		// insert using UTC time, convert on display
		$timeStr = Carbon::now()->toDateTimeString();
		$data = [];

		foreach($permissionSlugs as $slug)
		{
			SlugContainer::validateOrDie($slug, 'slug', 'The Permission slug');

			$data[] = [
				'roles_slug' => $this->slug,
				'permissions_slug' => $slug,
				'created_at' => $timeStr,
				'updated_at' => $timeStr
			];
		}

		// fails on duplicate entry
		try{
			DB::table(Config::get('can.role_permission_table'))->insert($data);
		} catch(\Exception $e) {
			return false;
		}

		$this->updateAddPermissionsForRole($permissionSlugs, $timeStr);

		return true;
	}

	// update users having this role with new permissions
	protected function updateAddPermissionsForRole(array $permissionSlugs, $timeStr)
	{
		$userIds = $this->userIds();

		// and get all the permissions for those users
		$existingPerms = DB::table(Config::get('can.user_permission_table'))
			->whereIn('user_id', $userIds)
			->get();

		// since users may have different sets of roles, scan for the users that
		// don't have the new permissions and construct a bulk-insertable data set
		$newInserts = [];
		foreach($userIds as $currentId)
		{
			$userItems = array_filter($existingPerms, function($v) use($currentId) {
				return $v->user_id == $currentId;
			});

			$userPerms = array_map(function($v) {return $v->permissions_slug;}, $userItems);

			$toInsert = array_intersect($permissionSlugs, $userPerms);
			foreach($toInsert as $newPerm)
			{
				$newInserts[] = [
					'user_id' => $currentId,
					'permissions_slug' => $newPerm,
					'created_at' => $timeStr,
					'updated_at' => $timeStr
				];
			}
		}

		DB::table(Config::get('can.user_permission_table'))->insert($newInserts);
	}

	protected function updateRemovePermissionsForRole(array $permissionSlugs)
	{
		// FIXME - update for permissions added directly on the user (added_on_user = 1)
		// 1) get user ids for this role
		$userIds = $this->userIds();
		if(count($userIds) === 0){
			return;
		}

		// 2) find out if there are other roles with overlapping permissions. It's faster
		// to just suck up everything and post-process than it is to use a sub-select for MySQL < 6.0
		$allPermissions = DB::table(Config::get('can.role_permission_table'))->get();
		$thisRolePermissions = array_filter($allPermissions, function($value) {
			return $value['slug'] == $this->slug;
		});

		if(count($thisRolePermissions) === 0)
		{
			return;
		}

		$thisRolePermissionSlugs = array_map(function($v) {return $v->slug;}, $thisRolePermissions);

		// build preliminary user => permissions map of what we want to delete. We'll modify this
		// if there are other roles with overlapping permissions.
		$userPermissions = [];
		foreach($userIds as $userId)
		{
			$userPermissions[$userId] = $thisRolePermissionSlugs;
		}

		// find out if we have any roles with permissions that overlap this role
		$overlappingRolePermMap = [];
		foreach($allPermissions as $permission)
		{
			if($permission['role_slug'] == $this->slug)
			{
				continue;
			}

			$role = $permission['role_slug'];
			$perm = $permission['permissions_slug'];

			// test whether another role has permissions overlapping with this role;
			// create a map of roles with permissions that overlap permissions from this role
			if(in_array($perm, $thisRolePermissionSlugs, TRUE) )
			{
				if(!isset($overlappingRolePermMap[$role]))
				{
					$overlappingRolePermMap[$role] = [];
				}

				$overlappingRolePermMap[$role][] = $perm;
			}
		}

		// 3) if there are roles with overlapping permissions, find out if any of our role-users
		// also have these other roles
		if(count($overlappingRolePermMap) > 0)
		{
			$overlappingUsersRoles = DB::table(Config::get('can.user_role_table'))
				->whereIn('roles_slug', array_keys($overlappingRolePermMap))
				->get();

			if(count($overlappingUsersRoles) > 0)
			{
				foreach($overlappingUsersRoles as $userRole)
				{
					$userRole = $userRole['roles_slug'];
					$userId = $userRole['user_id'];

					$userPermsForRole = $overlappingRolePermMap[$userRole];
					$updatedUserPerms = array_diff($thisRolePermissionSlugs, $userPermsForRole);
					$userPermissions[$userId] = $updatedUserPerms;
				}
			}
		}

		// we built on a per-user basis. consolidate into sets of userIds that correspond to permission sets
		$deleteme = [];
		foreach($userPermissions as $userId => $permissions)
		{
			$userIsHandled = false;

			foreach($deleteme as $deleteSet)
			{
				// if this user's permissions are the same as another permission set we've already
				// binned, then add this user to the same permission set
				if($deleteSet['user_perms'] == $permissions)
				{
					$deleteSet['user_ids'][] = $userId;
				}
				$userIsHandled = true;
			}

			// otherwise, create a new bin for the permissions
			if(!$userIsHandled)
			{
				$deleteme[] = [
					'user_ids' => [$userId],
					'permissions' => [$permissions]
				];
			}
		}

		// delete where in this roles permissions, after removing exceptions. Exceptions
		// happen when a user has one or more other roles with with overlapping positions
		$first = array_shift($deleteme);
		$query = DB::table(Config::get('can.user_permission_table'))
			->whereIn('user_id',$first['user_ids'])->whereIn('permissions_slug', $first['permissions']);

		foreach($deleteme as $set)
		{
			$query = $query->orWhere(function($query) use($set) {
				$query->whereIn('user_id', $set['user_ids'])
					->whereIn('permissions_slug', $set['permissions']);
			});
		}

		$query->delete();
	}

	protected function userIds()
	{
		return DB::table(Config::get('can.user_role_table'))
			->whereIn('roles_slug',[$this->slug])
			->get(['user_id']);
	}

	public function permissions()
	{
		$permissionTable = Config::get('can.permission_table');
		$rolePermissionTable = Config::get('can.role_permission_table');
		$joinKeyFirst = $permissionTable.'.slug';
		$joinKeySecond = $rolePermissionTable.'.permissions_slug';
		$roleSlug = $rolePermissionTable.'.roles_slug';

		$raw = DB::table('permissions')
			->join($rolePermissionTable, $joinKeyFirst, '=', $joinKeySecond)
			->where($roleSlug,$this->slug)
			->get();

		$permissions = [];
		foreach($raw as $permission)
		{
			$permissions[] = new Permission($permission);
		}

		return $permissions;
	}

	public function users()
	{
		$userClass = Config::get('auth.model');
		$ids = $this->userIds();
		return $userClass::whereIn('id', $ids);
	}

	public function detachPermissions(array $permissionSlugs)
	{
		SlugContainer::validateOrDie($this->slug, 'slug', 'The Role slug');

		if(count($permissionSlugs) === 0)
		{
			return true;
		}

		$first = array_shift($permissionSlugs);
		SlugContainer::validateOrDie($first, 'slug', 'The Permission slug');

		$query = DB::table(Config::get('can.role_permission_table'))
			->where('roles_slug', $this->slug)
			->where('permissions_slug', $first);

		foreach($permissionSlugs as $slug)
		{
			SlugContainer::validateOrDie($slug, 'slug', 'The Permission slug');

			$roleSlug = $this->slug;
			$query->orWhere(function($q) use($roleSlug, $slug) {
				$q->where('roles_slug', $roleSlug)
					->where('permissions_slug', $slug);
			});
		}

		try
		{
			$query->delete();
		} catch(\Exception $e) {
			throw new \Exception('Failed to detach permissions: '.$e->getMessage());
		}

		return true;
	}


	/**
	 * Deletes all roles and permissions for all users. Be careful.
	 * Intended to be used prior to seeding to clean the Can tables.
	 */
	public static function removeAll()
	{
		DB::table(Config::get('can.role_table'))->truncate();
		DB::table(Config::get('can.permission_table'))->truncate();
		DB::table(Config::get('can.user_role_table'))->truncate();
		DB::table(Config::get('can.role_permission_table'))->truncate();
		DB::table(Config::get('can.user_permission_table'))->truncate();
	}

	// FIXME - this is incomplete and broken
	public static function remove($roleSlugToDelete, $reassignmentRole=null)
	{
		$userIds = DB::table(Config::get('can.user_role_table'))
			->where('roles_slug', $roleSlugToDelete)
			->get();

		if($reassignmentRole && count($userIds) > 0)
		{
			$newRoleExists = DB::table(Config::get('can.role_table'))
				->where('slug', $reassignmentRole)
				->count();

			if(!$newRoleExists)
			{
				throw new CanException("Role: Cannot reassign role. Role '$reassignmentRole' does not exist");
			}

			DB::table(Config::get('can.user_role_table'))
				->where('slug', $roleSlugToDelete)
				->update('slug', $reassignmentRole);

			// update permissions table
			$oldPermissions = DB::table(Config::get('can.role_permission_table'))
				->where('permissions_slug', $roleSlugToDelete)
				->get();

			// fixme - this is braindead. don't want to delete the permission,
			// want to delete the role, which means a way harder calculation
			// with overlapping roles like we've done before. Try to reuse that
			// code
			DB::table(Config::get('can.user_permission_table'))
				->whereIn('permissions_slug', $oldPermissions)
				->delete();

			$newPermissions = DB::table(Config::get('can.role_permission_table'))
				->where('permissions_slug', $reassignmentRole)
				->get();

			// HERE - get user ids with old role, iterate over both
			// new permissions and users
			/*
			$toInsert = [];
			foreach($newPermissions $perm)
			{
				$toInsert = [

				];
			}
			*/

		}

		DB::table('pivot_users_roles')
			->where('roles_slug', $roleSlugToDelete)
			->delete();

		DB::table('pivot_roles_permissions')
			->where('roles_slug', $roleSlugToDelete)
			->delete();

		$count = DB::table('roles')
			->where('slug', $roleSlugToDelete)
			->delete();

		return $count > 0;
	}

	public function hasPermission($permissionSlug)
	{
		$query = DB::table(Config::get('can.role_permission_table'))
			->where('roles_slug', $this->slug);

		// do this even though we have one permission to get proper wildcard behavior
		$container = new SlugContainer($permissionSlug);
		$query = $container->buildSlugQuery($query, 'permissions_slug');

		$permissions = $query->get();

		return count($permissions) > 0;
	}

	public function getPermissions()
	{
		$permissionTable = Config::get('can.permission_table');
		$rolePermissionTable = Config::get('can.role_permission_table');
		$joinKeyFirst = $permissionTable.'.slug';
		$joinKeySecond = $rolePermissionTable.'.permissions_slug';
		$roleSlug = $rolePermissionTable.'.roles_slug';
		$fields = $permissionTable.'.*';

		$values = DB::table($permissionTable)
			->join($rolePermissionTable,$joinKeyFirst, '=', $joinKeySecond)
			->where($roleSlug,$this->slug)
			->get([$fields]);

		$permissions = [];
		foreach($values as $v)
		{
			// because user can set db results to come back either way
			$values = is_object($v) ? get_object_vars($v) : $v;
			$permissions[] = new Permission($values);
		}

		return $permissions;
	}
}
