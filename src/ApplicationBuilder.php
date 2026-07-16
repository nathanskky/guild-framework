<?php declare(strict_types=1);

namespace Guild\Framework;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;

class ApplicationBuilder
{
    public function __construct(private Application $app)
    {}

    public function addRouting(): self
    {
        $routesFilePath = $this->app->get('path.base') . '/routes/routes.php';

        $callable = require $routesFilePath;

        if (is_callable($callable)) {
            $callable($this->app->get(Router::class));
        }

        return $this;
    }

    public function addAuthentication(): self
    {
        $this->app->addServiceProvider(new AuthenticationServiceProvider());

        return $this;
    }

    public function addIlluminateDatabase(): self
    {
        $configFilePath = $this->app->get('path.config') . '/database.php';

        $config = require $configFilePath;

        $capsule = new Capsule;
        $capsule->addConnection($config);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->addShared(Capsule::class, $capsule);

        return $this;
    }

    /**
     * Get the application instance.
     *
     * @return Application
     */
    public function create(): Application
    {
        return $this->app;
    }
}