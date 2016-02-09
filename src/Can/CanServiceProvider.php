<?php namespace jjharr\Can;
/**
 *
 * @license MIT
 * @package Can
 */
use jjharr\Can\Commands\CanMigrationsCommand;
use Illuminate\Support\ServiceProvider;

class CanServiceProvider extends ServiceProvider
{
	/**
	 * Set to false to enable loading of command
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		// Publish config files
		$this->publishes([
			__DIR__.'/Config/can.php' => config_path('can.php'),
		]);

		// Register commands
		$this->commands('command.can.migration');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->registerCommands();
	}

	/**
	 * Register the artisan commands.
	 *
	 * @return void
	 */
	private function registerCommands()
	{
		$this->app->singleton('command.can.migration', function ($app) {
			return new CanMigrationsCommand();
		});
	}

	/**
	 *
	 * @return array
	 */
	public function provides()
	{
		return array(
			'command.can.migration'
		);
	}
}
