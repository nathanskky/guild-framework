<?php

declare(strict_types=1);

namespace Guild\Framework;

use Error;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\ServiceProvider\RivetServiceProvider;
use Guild\Framework\ServiceProvider\ViewServiceProvider;
use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Rivet\Page\PageDefaults;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use League\Container\ReflectionContainer;
use Psr\Log\LoggerInterface;

readonly class ApplicationBuilder
{
    public function __construct(private Application $app)
    {
    }

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
        $this->app->markAuthenticationAdded();

        return $this;
    }

    /**
     * Resolve the authenticated user's Grouper groups to roles and permissions,
     * and make the current user available through UserResolver.
     *
     * Arguments carry code-level facts; config/authorization.php carries the
     * settings that vary by deployment. With IdentitySource::Oidc this must
     * come after addAuthentication(). A CAS application never calls
     * addAuthentication(), because Apache authenticates before PHP runs.
     *
     * Policies are resolved through the container when first used, so their
     * constructor dependencies need autowiring or explicit bindings.
     *
     * @param  class-string<Permission>  $permissions  The application's permission enum.
     * @param  list<class-string>  $policies  Policy classes, each declaring #[HandlesResource].
     */
    public function addAuthorization(IdentitySource $identitySource, string $permissions, array $policies = []): self
    {
        if ($identitySource === IdentitySource::Oidc && ! $this->app->isAuthenticationAdded()) {
            throw new ConfigurationException(
                'addAuthorization() was given IdentitySource::Oidc, but addAuthentication() has not been called. '
                . 'Call addAuthentication() first, or use IdentitySource::Cas if Apache authenticates requests.'
            );
        }

        $configFilePath = $this->app->getPath('config') . '/authorization.php';

        try {
            $config = require $configFilePath;
        } catch (Error | GrouperConfigurationException $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read authorization config file: '
                . $error->getMessage(),
                previous: $error,
            );
        }

        if (! $config instanceof AuthorizationConfiguration) {
            throw new ConfigurationException(
                'Authorization configuration file must return an instance of AuthorizationConfiguration.'
            );
        }

        $this->app->addServiceProvider(new AuthorizationServiceProvider($identitySource, $permissions, $config, $policies));

        return $this;
    }

    /**
     * Replace the default logger, which writes through PHP's error_log().
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->app->addShared(LoggerInterface::class, $logger, overwrite: true);

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

        $capsule = new Capsule();
        $capsule->addConnection($config);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->addShared(Capsule::class, $capsule);

        return $this;
    }

    public function addTemplateEngine(TemplateEngine $engine): self
    {
        $this->app->setTemplateEngine($engine);
        $this->app->addServiceProvider(new ViewServiceProvider($engine));

        return $this;
    }

    /**
     * Make the Rivet Design System components available to the template engine.
     *
     * Takes no configuration file, like addTemplateEngine(). It does require one though:
     * the components are registered against whichever engine was chosen, so this must
     * come after addTemplateEngine().
     *
     * The optional PageDefaults is passed through to the renderer for the rvt_page
     * layout component to read application-wide values (app title, navigation, footer
     * links) from.
     */
    public function addRivet(?PageDefaults $pageDefaults = null): self
    {
        $engine = $this->app->getTemplateEngine();

        if ($engine === null) {
            throw new ConfigurationException(
                'addRivet() needs a template engine to register the components with. Call addTemplateEngine() first.'
            );
        }

        $this->app->addServiceProvider(new RivetServiceProvider($engine, $pageDefaults));

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
