<?php
/**
 * This is the config file for the Can roles and permissions package
 *
 * @license MIT
 * @package jjharr\Can
 */
return array(

	/*
	| Can role Table
	|
	| This is the roles table used by Can to save roles to the database.
	|
	*/
	'role_table' => 'roles',

	/*
	| Can permission Table
	|
	| This is the permissions table used by Can to save permissions to the
	| database.
	|
	*/
	'permission_table' => 'permissions',

	/*
	| Can user_role Table
	|
	| This is the role_user table used by Can to save assigned roles to the
	| database.
	|
	*/
	'user_role_table' => 'pivot_users_roles',

	/*
	| Can role_permission Table
	|
	| This is the permission_role table used by Can to save relationship
	| between permissions and roles to the database.
	|
	*/
	'role_permission_table' => 'pivot_roles_permissions',

	/*
	| Can user_permission Table
	|
	| This is the role_user table used by Can to save assigned roles to the
	| database.
	|
	*/
	'user_permission_table' => 'pivot_users_permissions',
);
