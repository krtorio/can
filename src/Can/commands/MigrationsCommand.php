<?php

namespace Can\commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class MigrationsCommand extends Command {

	protected $name = 'can:migration';

	protected $description = 'Create migrations for the Can package';

	public function fire()
	{
		$this->laravel->view->addNamespace('can', substr(__DIR__, 0, -8).'views');

		$this->line('');
		$this->info('Attempting to create Can migration tables ...');

		try {
			$this->writeMigrationFile();
			$this->info("Migration created!");
		} catch(\Exception $e) {
			$this->error($e->getMessage());
		}

		$this->line('');
	}

	protected function params()
	{
		$userModel = Config::get('auth.model');
		$userPrimaryKey = (new $userModel())->getKeyName();

		return [
			'rolesTable' => Config::get('can.roles_table'),
			'permissionsTable' => Config::get('can.permissions_table'),
			'usersRolesTable' => Config::get('can.users_roles_table'),
			'rolesPermissionsTable' => Config::get('can.roles_permissions_table'),
			'usersPermissionsTable' => Config::get('can.users_permissions_table'),
			'userTable' => Config::get('auth.table'),
			'userModel' => $userModel,
			'userPrimaryKey' => $userPrimaryKey
		];
	}

	protected function writeMigrationFile()
	{
		$filename = base_path("/database/migrations")."/".date('Y_m_d_His')."_create_can_tables.php";

		$output = $this->laravel->view->make('can::migrations')
			->with($this->params())
			->render();

		if (file_exists($filename)) {
			throw new \Exception('Migration file already exists');
		}

		$file = fopen($filename, 'x');
		fwrite($file, $output);
		fclose($file);
	}
}
