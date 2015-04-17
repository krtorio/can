<?php

namespace Can;

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
trait Can {

	protected $message;

	public function is($roles)
	{
		/*
		 * parse
		 * query for roles. select
		 */
		$roles = $this->parseRoles($roles);


	}

	public function can($permissions)
	{

	}

	public function also(callable $cb, $params, $message='')
	{

	}

	public function getRoles()
	{

	}

	public function getPermissions()
	{

	}

	protected function parseRoles($roles)
	{

	}

	protected function parsePermissions($permissions)
	{

	}
}
