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
		//$this->registerCan();
		$this->registerCommands();
		$this->mergeConfig();
	}

	/**
	 * Register the application bindings.
	 *
	 * @return void
	 */
	private function registerCan()
	{
		//$this->app->bind('can', function ($app) {
		//	return new Can($app);
		//});
	}

	/**
	 * Register the artisan commands.
	 *
	 * @return void
	 */
	private function registerCommands()
	{
		$this->app->bindShared('command.can.migration', function ($app) {
			return new CanMigrationsCommand();
		});
	}

	/**
	 * Merges Can config
	 *
	 * @return void
	 */
	private function mergeConfig()
	{
		$this->mergeConfigFrom(
			__DIR__.'/Config/can.php', 'can'
		);
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
