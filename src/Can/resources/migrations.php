<?php
return <<<EOF
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCanTables extends Migration {

	public function up()
	{
		Schema::create('$permissionTable', function(Blueprint \$table)
		{
			\$table->string('slug', 255)->primary();
			\$table->string('name', 255)->nullable();
			\$table->string('description', 255)->nullable();
		});

		Schema::create('$roleTable', function(Blueprint \$table)
		{
			\$table->string('slug', 255)->primary();
			\$table->string('name', 255)->nullable();
			\$table->string('description', 255)->nullable();
		});

		Schema::create('$rolePermissionTable', function(Blueprint \$table)
		{
			\$table->string('roles_slug', 255);
			\$table->string('permissions_slug', 255);
			\$table->timestamps();

			\$table->primary(['roles_slug','permissions_slug']);
		});

		Schema::create('$userRoleTable', function(Blueprint \$table)
		{
			\$table->bigInteger('user_id');
			\$table->string('roles_slug', 255);
			\$table->timestamps();

			\$table->primary(['user_id', 'roles_slug']);
		});
		Schema::create('$userPermissionTable', function(Blueprint \$table)
		{
			\$table->bigInteger('user_id');
			\$table->string('permissions_slug', 255);
			\$table->boolean('added_on_user')->default(0);
			\$table->timestamps();

			\$table->primary(['user_id', 'permissions_slug']);
		});
	}

	public function down()
	{
		\$tables = [
			'$permissionTable',
			'$roleTable',
			'$rolePermissionTable',
			'$userRoleTable',
			'$userPermissionTable'
		];

		foreach(\$tables as \$table)
		{
			Schema::drop(\$table);
		}
	}
}
EOF;
