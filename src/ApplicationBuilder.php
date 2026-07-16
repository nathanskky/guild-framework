<?php declare(strict_types=1);

namespace Guild\Framework;

use Error;
use Guild\Framework\Exception\ConfigurationException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use League\Container\ReflectionContainer;

readonly class ApplicationBuilder
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
        $configFilePath = $this->app->get('path.config') . '/authentication.php';

        try {
            $config = require $configFilePath;
        } catch (Error $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read authentication config file: '
                . $error->getMessage()
            );
        }

        $this->app->addServiceProvider(new AuthenticationServiceProvider($config));

        return $this;
    }

    public function addIlluminateDatabase(): self
    {
        $configFilePath = $this->app->get('path.config') . '/database.php';

        try {
            $config = require $configFilePath;
        } catch (Error $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read database config file: '
                . $error->getMessage()
            );
        }

        $capsule = new Capsule;
        $capsule->addConnection($config);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->addShared(Capsule::class, $capsule);

        return $this;
    }

    public function enableAutoWiring(): self
    {
        $this->app->delegate(new ReflectionContainer(cacheResolutions: true));

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