<?php
namespace Ram\Deployer;

use Illuminate\Support\ServiceProvider;

class DeployerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/deployer.php' => config_path('deployer.php'),
        ], 'deployer-config');

        $this->mergeConfigFrom(__DIR__.'/../config/deployer.php', 'deployer');

        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Ram\Deployer\Console\LaraDeployCommand::class,
                \Ram\Deployer\Console\LaraRemoteCmdCommand::class,
                \Ram\Deployer\Console\LaraLogsCommand::class,
            ]);
        }
    }
}
