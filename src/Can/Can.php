<?php

namespace jjharr\Can;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait Can {

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

		// insert role relationship, composite key ensures exception for duplicate slug/id pairs
		try {
			DB::table(Config::get('can.user_role_table'))->insert([
				'roles_slug' => $roleSlug,
				'user_id' => $this->id,
				'created_at' => $timeStr,
				'updated_at' => $timeStr
			]);
		} catch(\Exception $e) {
			// failure is usually going to be because the role was already added, so no need to barf.
			Log::warning('Unable to attach role: '.$e->getMessage());
			return false;
		}

		$this->addPermissionsForRole($role, $timeStr);

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
		$currentPermissions = $this->getPermissions();
		$rolePermissions = $role->getPermissions();
		$newPermissions = array_diff($rolePermissions, $currentPermissions);

		$permData = array_map(function($v) use ($timeStr) {
			return [
				'permissions_slug' => $v->slug,
				'user_id' => $this->id,
				'created_at' => $timeStr,
				'updated_at' => $timeStr
			];
		}, $newPermissions);

		DB::table(Config::get('can.user_permission_table'))->insert($permData);
	}

	public function detachRole($roleSlug)
	{
		// todo - does this weed out wildcards?
		SlugContainer::validateOrDie($roleSlug, 'slug');
		$userId = $this->id;

		/*
		 * todo -
		 * 1+2 is like is(). Can we just run that and cache roles?
		 * 3-6 is remove role
		 */
		//
		// 1) look up all the attached roles for the user
		$roles = $this->getRoles();

		// 2) make sure the role to detach is among the attached roles
		$allRoleSlugs = array_map(function($v) {
			return $v->slug;
		}, $roles);

		if(!in_array($roleSlug, $allRoleSlugs, TRUE))
		{
			return false;
		}

		// 3) remove the role pivot entry
		DB::table(Config::get('can.user_role_table'))
			->where('user_id', $this->id)
			->where('roles_slug', $roleSlug)
			->delete();

		// 4) get all the permission slugs for those roles
		$rolePermissions = DB::table(Config::get('can.role_permission_table'))
			->whereIn('roles_slug', $allRoleSlugs)
			->get(['permissions_slug', 'roles_slug']);

		// 5) find the ones that should be removed. start with the permissions for the selected role, then
		// remove any that also belong to other attached roles. Do this by reducing all the role permissions
		// to slug array, then calling array_diff with the target role first.

		$targetRolePerms = array_filter($rolePermissions, function($v) use($roleSlug) {
			return $v->roles_slug === $roleSlug;
		});

		$targetRolePerms = array_map(function($v) { return $v->roles_slug; }, $targetRolePerms);

		foreach($rolePermissions as $item)
		{
			if($item->roles_slug !== $roleSlug && in_array($item->permissions_slug, $targetRolePerms, TRUE))
			{
				delete($item->permissions_slug, $targetRolePerms);
			}
		}

		// 6) remove all the permissions from 5), minus any that have been explicitly set by attachPermissions
		if(count($targetRolePerms) > 0)
		{
			$first = array_shift($targetRolePerms);
			$query = DB::table(Config::get('can.user_permission_table'))->where('user_id', $this->id)->where('permissions_slug',$first);
			foreach($targetRolePerms as $perm)
			{
				$slug = $perm->permissions_slug;
				$query->orWhere(function($query) use($userId, $slug) {
					$query->where('user_id', $userId)->where('permissions_slug', $slug);
				});
			}
			$query->delete();
		}

		return true;
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
			return true;
		}

		return false;
	}

	public function detachPermission($permissionSlug)
	{
		$affected = DB::table(Config::get('can.user_permission_table'))
			->where('user_id', $this->id)
			->where('permissions_slug', $permissionSlug)
			->where('added_on_user', 1)
			->delete();

		return $affected > 0;
	}

	public function is($roles)
	{
		// todo - possibly refactor to use getRoles? then have detachRole use this?
		$query = DB::table(Config::get('can.user_role_table'))->where('user_id', $this->id);

		$container = new SlugContainer($roles);
		$query = $container->buildSlugQuery($query, 'roles_slug');

		return count($query->get()) > 0;
	}

	public function can($permissions)
	{
		$query = DB::table(Config::get('can.user_permission_table'))->where('user_id',$this->id);

		$container = new SlugContainer($permissions);
		$query = $container->buildSlugQuery($query, 'permissions_slug');

		return count($query->get()) > 0;
	}

	public function getRoles()
	{
		$roleTable = Config::get('can.role_table');
		$userRoleTable = Config::get('can.user_role_table');

		$queryParams = [
			'joinKeyFirst' => $roleTable.'.slug',
			'joinKeySecond' => $userRoleTable.'.slug',
			'userIdKey' => $userRoleTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($roleTable)
			->join($userRoleTable, function($query) use($queryParams) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);
			})
			->get([$roleTable.'.*']);

		return array_map(function($v) {
			return new Role($v);
		}, $data);
	}

	public function getPermissions()
	{
		$permissionTable = Config::get('can.permission_table');
		$userPermissionTable = Config::get('can.user_permission_table');

		$queryParams = [
			'joinKeyFirst' => $permissionTable.'.slug',
			'joinKeySecond' => $userPermissionTable.'.permissions_slug',
			'userIdKey' => $userPermissionTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($permissionTable)
			->join($userPermissionTable, function($query) use($queryParams) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);
			})
			->get([$permissionTable.'.*']);

		return array_map(function($v) {
			return new Permission($v);
		}, $data);
	}
}
