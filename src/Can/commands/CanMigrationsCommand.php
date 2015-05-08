<?php

namespace Can\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CanMigrationsCommand extends Command {

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
			'roleTable'          => Config::get('can.role_table'),
			'permissionTable'    => Config::get('can.permission_table'),
			'rolePermissionTable' => Config::get('can.role_permission_table'),
			'userRoleTable'       => Config::get('can.user_role_table'),
			'userPermissionTable' => Config::get('can.user_permission_table'),
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
