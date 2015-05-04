<?php

namespace Can\views;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CanTables extends Migration {

	public function up()
	{
		Schema::create('permissions', function(Blueprint $table)
		{
			$table->string('slug', 255)->primary();
			$table->string('name', 255)->nullable();
			$table->string('description', 255)->nullable();
		});

		Schema::create('roles', function(Blueprint $table)
		{
			$table->string('slug', 255)->primary();
			$table->string('name', 255)->nullable();
			$table->string('description', 255)->nullable();
		});

		Schema::create('pivot_roles_permissions', function(Blueprint $table)
		{
			$table->string('roles_slug', 255);
			$table->string('permissions_slug', 255);
			$table->timestamps();

			$table->primary(['roles_slug','permissions_slug']);
		});

		Schema::create('pivot_users_permissions', function(Blueprint $table)
		{
			$table->bigInteger('user_id');
			$table->string('permissions_slug', 255);
			$table->boolean('added_on_user');
			$table->timestamps();

			$table->primary(['user_id', 'permissions_slug']);
		});

		Schema::create('pivot_users_roles', function(Blueprint $table)
		{
			$table->bigInteger('user_id');
			$table->string('roles_slug', 255);
			$table->timestamps();

			$table->primary('user_id', 'roles_slug');
		});

	}

	public function down()
	{
		Schema::drop('permissions');
		Schema::drop('roles');
		Schema::drop('pivot_roles_permissions');
		Schema::drop('pivot_users_permissions');
		Schema::drop('pivot_users_roles');
	}
}
