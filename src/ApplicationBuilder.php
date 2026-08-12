<?php declare(strict_types=1);

namespace Guild\Framework;

use Error;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\ServiceProvider\ViewServiceProvider;
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
        $routesFilePath = $this->app->getPath('base') . '/routes/routes.php';

        $callable = require $routesFilePath;

        if (is_callable($callable)) {
            $callable($this->app->get(Router::class));
        }

        return $this;
    }

    public function addAuthentication(): self
    {
        $configFilePath = $this->app->getPath('config') . '/authentication.php';

        try {
            $config = require $configFilePath;
        } catch (Error $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read authentication config file: '
                . $error->getMessage()
            );
        }

        if (! $config instanceof OidcConfiguration) {
            throw new ConfigurationException('Authentication configuration file must return an instance of OidcConfiguration.');
        }

        $this->app->addServiceProvider(new AuthenticationServiceProvider($config));

        return $this;
    }

    public function addIlluminateDatabase(): self
    {
        $configFilePath = $this->app->getPath('config') . '/database.php';

        try {
            $config = require $configFilePath;
        } catch (Error $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read database config file: '
                . $error->getMessage()
            );
        }

        if (!is_array($config)) {
            throw new ConfigurationException('Database configuration file must return an array.');
        }

        $capsule = new Capsule;
        $capsule->addConnection($config);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->addShared(Capsule::class, $capsule);

        return $this;
    }

    public function addTemplateEngine(TemplateEngine $engine): self
    {
        $this->app->addServiceProvider(new ViewServiceProvider($engine));

        return $this;
    }

    public function enableAutoWiring(bool $cacheResolutions = true): self
    {
        $this->app->delegate(new ReflectionContainer(cacheResolutions: $cacheResolutions));

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