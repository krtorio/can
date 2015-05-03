<?php

namespace jjharr\Can;

/*
 * how to use this whole freaking system :
 *
 * PERMISSIONS
 * - create permissions using a seeder or artisan like
 * Permission::create([ ... ]);
 *
 * we should do this as resource based or not?
 * yes :
 * Call this a Resource, then slugs are permissions for it
 * Resource slugs must be unique
 * We only store slug,display,id though. It's implicit that the first part of the dot notation
 * of the slug is the resource. Or maybe we store resource and only path after that?
 *
 * User interface is :
 * 1) can('post.edit', $tests, Permission:Bool|Permission:Detail)
 * 2) can('post.edit.description,post.edit.title', $tests); // AND
 * 3) can('post.edit.author|post.publish', $tests); // OR
 * 4) can('post.edit.*', $tests); // can edit anything
 *
 * 2nd arg of can could get ugly
 * or can(...)->and(callable, error msg)? // prettier, can't get array of results back from this
 * could add something like permission error? Do this.
 *
 * addPerm(and syntax only)
 * detachPerm(and syntax only)
 *
 * creation:
 * artisan individually
 * or in seeder: PermissionResource
 *
 * ROLES
 *
 * looks like :
 * is('x|y');
 * is('x,y');
 *
 * attachRole(and syntax only)
 * detachRole(and syntax only)
 *
 * MODELS
 *
 * if they extend our eloquent model with our magic call, then they could expand
 * their model permissions to stuff like :
 * Post->canEditAuthor()
 *
 * they would have to define a protected static $permission = 'post' or whatever
 *
 * SCOPE
 * part of user trait, calling roles(xxx) will limit a query to those having a certain role
 *
 * EXCEPTIONS
 * have a custom exception CantException (or more boring, UnauthorizedException)
 *
 * DELETE
 * - Deleting a permission should delete it from all roles?
 * - removing a user should delete the join table entries to their roles
 *
 * BLADE
 * - @role, @endrole
 * -@can, @endcan, @else (@cant?)
 *
 * ARTISAN
 *
 * can:addPerm, can:removePerm, can:add/removeRole,
 */

use Carbon\Carbon;
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
		SlugContainer::validateOrDie($roleSlug, 'slug');

		$role = Role::single($roleSlug);
		if(empty($role))
		{
			throw new CanException("There is no role with the slug: $roleSlug");
		}

		$timeStr = Carbon::now()->toDateTimeString();

		// insert role relationship, composite key ensures exception for duplicate slug/id pairs
		try {
			DB::table('pivot_users_roles')->insert([
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
		// only insert permissions that the user doesn't already have.
		$currentPermissions = $this->getUserPermissions();
		$rolePermissions = $role->getPermissions();
		$newPermissions = array_diff($rolePermissions, $currentPermissions);

		$permData =[];
		foreach($newPermissions as $p)
		{
			$permData[] = [
				'permissions_slug' => $p->slug,
				'user_id' => $this->id,
				'created_at' => $timeStr,
				'updated_at' => $timeStr
			];
		}

		DB::table('pivot_users_permissions')->insert($permData);
	}

	public function detachRole($roleSlug)
	{
		/*
		 * The detachRole problem : how to know whether removing a role should also
		 * remove permissions for that role?
		 *
		 * case 1) user has 2 roles with overlapping permissions. How to know when to
		 * delete the permission? Answer: look up all the user's roles to see if there
		 * are multiple references to the permission.
		 *
		 * case 2) user-added permissions. This is harder. Probably need a separate
		 * column to track user-added permissions. if true, the permission can only
		 * be removed by detachPermission.
		 *
		 * case 3) TODO I need to make sure that when permissions are added or removed
		 * from a role, that change is reflected in the user_permissions table. So
		 * for role that is add/remove permission
		 */

		// query for non-optimized 'can' for other packages :
		// it's essentially two queries - one to look up roles, one to get permissions for those roles
		// select * from permissions as Perm join pivot_roles_permissions as Pivot on Perm.slug=Pivot.permissions_slug where
		// Pivot.roles_slug
		//
		// query for non-optimized 'can' for ours
		//
		// query for optimized can for ours

		// todo - does this weed out wildcards?
		SlugContainer::validateOrDie($roleSlug, 'slug');
		$userId = $this->id;

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
		DB::table('pivot_users_roles')
			->where('user_id', $this->id)
			->where('roles_slug', $roleSlug)
			->delete();

		// 4) get all the permission slugs for those roles
		$rolePermissions = DB::table('pivot_roles_permissions')
			->whereIn('roles_slug', $allRoleSlugs)
			->get('permissions_slug', 'roles_slug');

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
			$query = DB::table('pivot_users_permissions')->where('user_id', $this->id)->where('permissions_slug',$first);
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
		$exists = DB::table('permissions')->where('slug', $permissionSlug)->count();

		if(count($exists))
		{
			DB::table('pivot_users_permissions')->insert([
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
		$affected = DB::table('pivot_users_permissions')
			->where('user_id', $this->id)
			->where('permissions_slug', $permissionSlug)
			->where('added_on_user', 1)
			->delete();

		return $affected > 0;
	}

	public function is($roles)
	{
		$query = DB::table('pivot_users_roles')->where('user_id', $this->id);

		$container = new SlugContainer($roles);
		$query = $container->buildSlugQuery($query, 'roles_slug');

		$hits = $query->distinct()->get();

		return count($hits) > 0;
	}

	public function can($permissions)
	{
		$query = DB::table('join_user_permissions')->where('user_id',$this->id);

		$container = new SlugContainer($permissions);
		$query = $container->buildSlugQuery($query, 'permissions_slug');

		$hits = $query->get();
		return count($hits) > 0;
	}

	public function also(callable $cb, $params, $message='')
	{
		// TODO
	}

	public function getRoles()
	{
		$userId = $this->id;
		$raw = DB::table('roles')
			->join('pivot_users_roles', function($query) use($userId) {
				$query->on('roles.slug', '=', 'pivot_users_roles.slug')
					->where('pivot_users_roles.user_id', '=', $userId);
			})
			->get();

		$roles = [];
		foreach($raw as $item)
		{
			$roles[] = new Role($item);
		}

		return $roles;
	}

	public function getUserPermissions()
	{
		$userId = $this->id;
		$data = DB::table('permissions')
			->join('pivot_users_permissions','permissions.slug','=','pivot_users_permissions.permissions_slug')
			//->join('pivot_users_permissions', function($query) use($userId) {
			//	$query->on('permissions.slug', '=', 'pivot_users_permissions.slug')
			//		->where('pivot_users_permissions.user_id', '=', $userId);
			//})
			->where('pivot_users_permissions.user_id',$this->id)
			->get();

		$permissions = [];
		foreach($data as $hit)
		{
			$permissions[] = new Permission($hit);
		}

		return $permissions;
	}
}
