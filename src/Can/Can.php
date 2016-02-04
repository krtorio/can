<?php

namespace jjharr\Can;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait Can {

	/*
	 * A cache of the user's roles. Use <code>getRoles()</code> instead of accessing this variable.
	 */
	private $userRoles;

	/**
	 * A cache of the user's permissions. Use <code>getPermissions()</code> instead of accessing this variable.
	 */
	private $userPermissions;

	/**
	 * Accepts a single role slug, and attaches that role to the user. Does nothing
	 * if the user is already attached to the role.
	 *
	 * @param $roleSlug string
	 *
	 * @return bool
	 */
	public function attachRole($roleSlug)
	{
		$role = Role::single($roleSlug);
		if(empty($role))
		{
			throw new CanException("There is no role with the slug: $roleSlug");
		}

		$timeStr = Carbon::now()->toDateTimeString();

		if($this->is($roleSlug))
		{
			return $role;
		}

		DB::table(Config::get('can.user_role_table'))->insert([
			'roles_slug' => $roleSlug,
			'user_id' => $this->id,
			'created_at' => $timeStr,
			'updated_at' => $timeStr
		]);

		$this->addPermissionsForRole($role, $timeStr);
		$this->invalidateRoleCache();

		return $role;
	}

	/**
	 * After adding role, add permissions for that role to the user/permissions
	 * table to make can() a faster call.
	 *
	 * @param Role $role
	 * @param      $timeStr
	 */
	protected function addPermissionsForRole(Role $role, $timeStr)
	{
		$newPermissions = $this->uniquePermissionsForRole($role);

		if(count($newPermissions))
		{
			$permData = array_map(function($v) use ($timeStr) {
				return [
					'permissions_slug' => $v->slug,
					'user_id' => $this->id,
					'created_at' => $timeStr,
					'updated_at' => $timeStr
				];
			}, $newPermissions);

			DB::table(Config::get('can.user_permission_table'))->insert($permData);
			$this->invalidatePermissionCache();
		}
	}

	/**
	 * Detach a role from the user
	 *
	 * @param $roleSlug
	 *
	 * @return bool
	 * @throws CanException
	 */
	public function detachRole($roleSlug)
	{
		// todo - does this weed out wildcards?
		SlugContainer::validateOrDie($roleSlug, 'slug');

		// make sure the role to detach is among the attached roles
		$allRoleSlugs = $this->slugsFor( $this->getRoles() );

		if(!in_array($roleSlug, $allRoleSlugs, TRUE))
		{
			return false;
		}

		$this->doDetachRole($roleSlug);

		$this->detachRolePermissions($roleSlug);

		return true;
	}


	/**
	 * returns an array of slugs given an array of Role or Permission objects
	 *
	 * @param array $rolesOrPermissions
	 *
	 * @return array
	 */
	protected function slugsFor(array $rolesOrPermissions)
	{
		// todo - move this to slugcontainer. Change slugcontainer into some
		// other name.
		return array_map(function($v) {
			return $v->slug;
		}, $rolesOrPermissions);
	}

	protected function doDetachRole($roleSlug)
	{
		DB::table(Config::get('can.user_role_table'))
			->where('user_id', $this->id)
			->where('roles_slug', $roleSlug)
			->delete();

		$this->invalidateRoleCache();
	}


	/**
	 * Remove the permissions for a role from the user. Permissions that have been explicitly set
	 * on the user and permissions that also belong to another of the user's role are not removed.
	 *
	 * @param $targetRoleSlug
	 * @param $userRoleSlugs
	 *
	 * @throws CanException
	 */
	protected function detachRolePermissions($targetRoleSlug)
	{
		$targetRole = Role::single($targetRoleSlug);
		$uniqueRolePermissions = $this->uniquePermissionsForRole($targetRole);

		$uniqueSlugs = array_map(function($o) {
			return $o->slug;
		}, $uniqueRolePermissions);

		// then delete what remains
		if(count($uniqueRolePermissions) > 0)
		{
			DB::table(Config::get('can.user_permission_table'))
				->where('user_id', $this->id)
				->whereIn('permissions_slug',$uniqueSlugs)
				->delete();

			$this->invalidatePermissionCache();
		}
	}

	/**
	 * Permissions added directly on the user can only be removed using
	 * detachPermission. Removing a role from the user that contains the
	 * added permission will NOT remove a permission added through this
	 * method.
	 *
	 * @param $permissionSlugs
	 *
	 * @return bool
	 */
	public function attachPermission($permissionSlug)
	{
		$exists = DB::table(Config::get('can.permission_table'))->where('slug', $permissionSlug)->count();

		if(count($exists))
		{
			DB::table(Config::get('can.user_permission_table'))->insert([
				'user_id' => $this->id,
				'permissions_slug' => $permissionSlug,
				'added_on_user' => 1
			]);

			$this->invalidatePermissionCache();

			return true;
		}

		return false;
	}


	/**
	 * Detach a permission from the user. This can only be called for permissions that were set explicitly
	 * on the user using <code>attachPermission()</code> and not for implicit permissions that are
	 * inherited through one of the user's roles.
	 *
	 * @param $permissionSlug
	 *
	 * @return bool
	 */
	public function detachPermission($permissionSlug)
	{
		// todo - allow a comma-separated list?
		$affected = DB::table(Config::get('can.user_permission_table'))
			->where('user_id', $this->id)
			->where('permissions_slug', $permissionSlug)
			->where('added_on_user', 1)
			->delete();

		if($affected)
		{
			$this->invalidatePermissionCache();
		}

		return $affected > 0;
	}


	/**
	 * Determine whether the user has a role matching the arguments
	 *
	 * @param $roles string|array Can be a single fully- or partially-qualified role, or a pipe-separated list of them
	 *
	 * @return bool
	 */
	public function is($roles)
	{
		// todo - possibly refactor to use getRoles? then have detachRole use this?
		$query = DB::table(Config::get('can.user_role_table'))->where('user_id', $this->id);

		$container = new SlugContainer($roles);
		$query = $container->buildSlugQuery($query, 'roles_slug');

		return count($query->get()) > 0;
	}


	/**
	 * Determine whether the user has permissions matching the arguments
	 *
	 * @param $permissions Can be a single fully- or partially-qualified permission, or a pipe-separated list of them
	 *
	 * @return bool
	 */
	public function can($permissions)
	{
		$query = DB::table(Config::get('can.user_permission_table'))->where('user_id',$this->id);

		$container = new SlugContainer($permissions);
		$query = $container->buildSlugQuery($query, 'permissions_slug');

		return count($query->get()) > 0;
	}


	/**
	 * Get the user's roles
	 *
	 * @return array
	 */
	public function getRoles()
	{
		if(!empty($this->userRoles))
		{
			return $this->userRoles;
		}

		$roleTable = Config::get('can.role_table');
		$userRoleTable = Config::get('can.user_role_table');

		$queryParams = [
			'joinKeyFirst' => $roleTable.'.slug',
			'joinKeySecond' => $userRoleTable.'.roles_slug',
			'userIdKey' => $userRoleTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($roleTable)
			->join($userRoleTable, function($query) use($queryParams) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);
			})
			->get([$roleTable.'.*']);

		$this->userRoles = array_map(function($v) {
			return new Role((array) $v);
		}, $data);

		return $this->userRoles;
	}

	/**
	 * Get the user's permissions. Valid filter values are :
	 *
	 * 'all' : get all permissions. This is the default
	 * 'role' : get only permissions that user has through a role, and are not explicit
	 * 'explicit' : get only the user's explicit permissions. These are permissions that have been directly set on the user.
	 *
	 * @param string $filter
	 *
	 * @return array
	 */
	public function getPermissions($filter='all')
	{
		// the permission cache contains all the user's permissions, and does not contain enough information
		// to execute the 'role' or 'explicit' filters.
		if($filter == 'all' && !empty($this->userPermissions))
		{
			return $this->userPermissions;
		}

		$permissionTable = Config::get('can.permission_table');
		$userPermissionTable = Config::get('can.user_permission_table');

		$queryParams = [
			'joinKeyFirst' => $permissionTable.'.slug',
			'joinKeySecond' => $userPermissionTable.'.permissions_slug',
			'userIdKey' => $userPermissionTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($permissionTable)
			->join($userPermissionTable, function($query) use($queryParams, $filter) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);

				if($filter == 'role')
				{
					$query->where('added_on_user', false);
				} else if($filter == 'explicit') {
					$query->where('added_on_user', true);
				}
			})
			->get([$permissionTable.'.*']);

		$permissions = array_map(function($v) {
			return new Permission($v);
		}, $data);

		if($filter == 'all')
		{
			$this->userPermissions = $permissions;
		}

		return $permissions;
	}


	/**
	 * Returns the permissions associated with the provided role that are:
	 * a) not provided by any other role that is currently attached to the user and
	 * b) have not been explicitly set on the user
	 *
	 * @param $role
	 *
	 * @return array
	 */
	private function uniquePermissionsForRole(Role $role)
	{
		// 1) get role permissions
		$rolePermissions = $role->getPermissions();
		$rolePermissionSlugs = array_column($rolePermissions, 'slug');

		// 2) get user roles, exluding the provided role if it's there
		$userRoles = array_filter($this->getRoles(), function($currRole) use($role) {
			return $currRole->slug !== $role->slug;
		});
		$userRoleSlugs = array_column($userRoles, 'slug');

		// 3) get all permissions associated with user roles above
		$rolePermissionTable = Config::get('can.role_permission_table');
		$otherRolePermissions = DB::table($rolePermissionTable)->whereIn('roles_slug', $userRoleSlugs)->get();
		$otherRolePermissionSlugs = array_column($otherRolePermissions, 'permissions_slug');

		// 4) get all permissions that have been explicitly set on the user
		$explicitPermissions = DB::table(Config::get('can.user_permission_table'))->where('added_on_user', 1)->get();
		$explicitPermissionSlugs = array_column($explicitPermissions, 'permissions_slug');

		// 5) all permission slugs not belonging to supplied permission
		$excludedPermissionSlugs = array_merge($otherRolePermissionSlugs, $explicitPermissionSlugs);

		// 6 diff
		$uniqueSlugs = array_diff($rolePermissionSlugs, $excludedPermissionSlugs);

		// 7 return objects corresponding to unique slugs
		$perms = array_filter($rolePermissions, function($o) use($uniqueSlugs) {
			return in_array($o->slug, $uniqueSlugs);
		});

		return $perms;
	}

	private function invalidateRoleCache()
	{
		$this->userRoles = null;
	}

	private function invalidatePermissionCache()
	{
		$this->userPermissions = null;
	}

}
