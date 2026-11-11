<?php

declare(strict_types=1);

namespace Guild\Framework;

use Error;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\AdminMenu;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Framework\Authorization\Membership\GrouperDirectoryConfiguration;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Controller\Authentication\SignOutController;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ApplicationExtension;
use Guild\Framework\Middleware\AuthorizationFailureResponder;
use Guild\Framework\Middleware\RequireSignIn;
use Guild\Framework\Middleware\RequireSystemAdmin;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\AuthenticationTemplateServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationServiceProvider;
use Guild\Framework\ServiceProvider\AuthorizationTemplateServiceProvider;
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
    /**
     * @param  list<ApplicationExtension>  $extensions  Booted by create().
     */
    public function __construct(private Application $app, private array $extensions = [])
    {
    }

    /**
     * Choose how this application signs people in. Meant for extensions,
     * whose configure() runs before the application's own calls. Each slot
     * can be chosen once.
     *
     * @param  class-string<SignInMethod>  $class
     */
    public function useSignInMethod(string $class): self
    {
        if (! is_subclass_of($class, SignInMethod::class)) {
            throw new ConfigurationException(
                $class . ' cannot be the sign-in method: it does not implement SignInMethod.'
            );
        }

        $this->app->claimedSlots->claimSignInMethod($class);

        return $this;
    }

    /**
     * Choose where users' groups come from. Meant for extensions, whose
     * configure() runs before the application's own calls. Each slot can be
     * chosen once.
     *
     * @param  class-string<GroupDirectory>  $class
     */
    public function useGroupDirectory(string $class): self
    {
        if (! is_subclass_of($class, GroupDirectory::class)) {
            throw new ConfigurationException(
                $class . ' cannot be the group directory: it does not implement GroupDirectory.'
            );
        }

        $this->app->claimedSlots->claimGroupDirectory($class);

        return $this;
    }

    /**
     * Choose the System Admin group's ACM label. Meant for extensions, whose
     * configure() runs before the application's own calls. Each slot can be
     * chosen once.
     */
    public function useSystemAdminGroup(string $label): self
    {
        if (trim($label) === '') {
            throw new ConfigurationException('The System Admin group label must not be blank.');
        }

        $this->app->claimedSlots->claimSystemAdminGroup($label);

        return $this;
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

    /**
     * Sign people in with IU Login, configured by config/authentication.php.
     * An extension that chose the sign-in method through useSignInMethod()
     * replaces that: the file is then not read.
     */
    public function addAuthentication(): self
    {
        $claim = $this->app->claimedSlots->signInMethod;

        if ($claim !== null) {
            $this->app->claimedSlots->takeover(sprintf(
                'Sign-in method %s was chosen by %s; config/authentication.php was not read.',
                $claim->value,
                $claim->claimant,
            ));
            $this->app->addServiceProvider(new AuthenticationServiceProvider($claim));
        } else {
            $this->app->addServiceProvider(new AuthenticationServiceProvider($this->readAuthenticationConfiguration()));
        }

        $this->app->markAuthenticationAdded();
        $this->registerSignOutRoute();

        $engine = $this->app->getTemplateEngine();

        if ($engine !== null) {
            $this->app->addServiceProvider(new AuthenticationTemplateServiceProvider($engine));
        }

        return $this;
    }

    /**
     * Read config/authentication.php: how to reach IU Login.
     */
    private function readAuthenticationConfiguration(): OidcConfiguration
    {
        $configFilePath = $this->app->getPath('config') . '/authentication.php';

        // A missing file would only raise a warning, which says nothing about what to create.
        if (! is_file($configFilePath)) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read authentication config file: '
                . 'config/authentication.php was not found. Create it, returning an OidcConfiguration.'
            );
        }

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

        return $config;
    }

    /**
     * Mount POST /framework/sign-out on the shared Router. Only POST is a route,
     * and VerifyCsrfToken checks the form's token before the sign-in method runs.
     */
    private function registerSignOutRoute(): void
    {
        $router = $this->app->get(Router::class);

        if (! $router instanceof Router) {
            throw new ConfigurationException('The container did not return a Router.');
        }

        $router->map('POST', '/framework/sign-out', [SignOutController::class, 'signOut'])
            ->lazyMiddleware(VerifyCsrfToken::class);
    }

    /**
     * Resolve the authenticated user's Grouper groups to roles and permissions,
     * and make the current user available through UserResolver.
     *
     * Arguments carry code-level facts; config/authorization.php and
     * config/grouper.php carry the settings that vary by deployment. With IdentitySource::Oidc this must
     * come after addAuthentication(). A CAS application never calls
     * addAuthentication(), because Apache authenticates before PHP runs.
     *
     * Policies are resolved through the container when first used, so their
     * constructor dependencies need autowiring or explicit bindings.
     *
     * With a template engine configured, before or after this call, templates
     * get can() and cannot(). With addRivet(), members of the System Admin
     * group see an administration menu in rvt_page's header, placed by
     * $adminMenu; pass null to leave the header alone.
     *
     * @param  class-string<Permission>  $permissions  The application's permission enum.
     * @param  list<class-string>  $policies  Policy classes, each declaring #[HandlesResource].
     */
    public function addAuthorization(
        IdentitySource $identitySource,
        string $permissions,
        array $policies = [],
        ?AdminMenu $adminMenu = new AdminMenu(),
    ): self {
        $signInClaim = $this->app->claimedSlots->signInMethod;

        // Checked before any config file is read: the claim would otherwise be silently
        // ignored, because CAS identities come from REMOTE_USER.
        if ($identitySource === IdentitySource::Cas && $signInClaim !== null) {
            throw new ConfigurationException(sprintf(
                '%s was chosen as the sign-in method by %s, but addAuthorization() was given IdentitySource::Cas. '
                . 'A chosen sign-in method needs IdentitySource::Oidc and addAuthentication().',
                $signInClaim->value,
                $signInClaim->claimant,
            ));
        }

        if ($identitySource === IdentitySource::Oidc && ! $this->app->isAuthenticationAdded()) {
            throw new ConfigurationException(
                'addAuthorization() was given IdentitySource::Oidc, but addAuthentication() has not been called. '
                . 'Call addAuthentication() first, or use IdentitySource::Cas if Apache authenticates requests.'
            );
        }

        // Read first: an application upgraded without moving its Grouper settings
        // otherwise fails on config/authorization.php with a less useful error.
        $directory = $this->readGrouperDirectoryConfiguration();

        $configFilePath = $this->app->getPath('config') . '/authorization.php';

        try {
            $config = require $configFilePath;
        } catch (Error $error) {
            // $grouper also matches $grouperTimeout and $grouperConnectTimeout;
            // GrouperConfiguration matches a positional first argument.
            $grouperLeftBehind = str_contains($error->getMessage(), '$grouper')
                || str_contains($error->getMessage(), 'GrouperConfiguration');

            throw new ConfigurationException(
                'Problem encountered while attempting to read authorization config file: '
                . $error->getMessage()
                . ($grouperLeftBehind
                    ? '. Grouper settings are read from config/grouper.php; remove grouper:, grouperTimeout: '
                        . 'and grouperConnectTimeout: from config/authorization.php.'
                    : ''),
                previous: $error,
            );
        }

        if (! $config instanceof AuthorizationConfiguration) {
            throw new ConfigurationException(
                'Authorization configuration file must return an instance of AuthorizationConfiguration.'
            );
        }

        $this->app->addServiceProvider(new AuthorizationServiceProvider(
            $identitySource,
            $permissions,
            $config,
            $directory,
            $policies,
            $adminMenu,
        ));
        $this->app->setAuthorizationPermissions($permissions);
        $this->registerAuthorizationRoutes($identitySource);
        $this->registerAuthorizationFailureResponses();

        $engine = $this->app->getTemplateEngine();

        if ($engine !== null) {
            $this->app->addServiceProvider(new AuthorizationTemplateServiceProvider($engine, $permissions));
        }

        return $this;
    }

    /**
     * Read config/grouper.php: how to reach live Grouper.
     */
    private function readGrouperDirectoryConfiguration(): GrouperDirectoryConfiguration
    {
        $configFilePath = $this->app->getPath('config') . '/grouper.php';

        // A missing require target is a fatal error that cannot be caught.
        if (! is_file($configFilePath)) {
            throw new ConfigurationException(
                'config/grouper.php was not found. addAuthorization() reads the Grouper connection settings from it: '
                . 'move the GrouperConfiguration out of config/authorization.php into config/grouper.php, '
                . 'which must return a GrouperDirectoryConfiguration.'
            );
        }

        try {
            $config = require $configFilePath;
        } catch (Error | GrouperConfigurationException $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read grouper config file: '
                . $error->getMessage(),
                previous: $error,
            );
        }

        if (! $config instanceof GrouperDirectoryConfiguration) {
            throw new ConfigurationException(
                'Grouper configuration file must return an instance of GrouperDirectoryConfiguration.'
            );
        }

        return $config;
    }

    /**
     * Mount the framework's administration pages under /framework/authorization
     * on the shared Router. Independent of addRouting(), which loads the
     * application's own routes; /framework is reserved, so the two never
     * collide, though an application catch-all registered first could shadow it.
     */
    private function registerAuthorizationRoutes(IdentitySource $identitySource): void
    {
        $router = $this->app->get(Router::class);

        if (! $router instanceof Router) {
            throw new ConfigurationException('The container did not return a Router.');
        }

        /** @var callable(\League\Route\RouteGroup): void $routes */
        $routes = require dirname(__DIR__) . '/routes/authorization.php';
        $group = $router->group('/framework/authorization', $routes);

        // A guest on the OIDC path is sent to sign in; under CAS, Apache must
        // protect these paths, and RequireSystemAdmin explains that to a guest.
        if ($identitySource === IdentitySource::Oidc) {
            $group->lazyMiddleware(RequireSignIn::class);
        }

        $group->lazyMiddleware(RequireSystemAdmin::class);
        $group->lazyMiddleware(VerifyCsrfToken::class);
    }

    /**
     * Answer the authorization exceptions any route throws, the application's
     * own included, with a sign-in challenge, a 403 or a 503.
     */
    private function registerAuthorizationFailureResponses(): void
    {
        $router = $this->app->get(Router::class);

        if (! $router instanceof Router) {
            throw new ConfigurationException('The container did not return a Router.');
        }

        $router->lazyMiddleware(AuthorizationFailureResponder::class);
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

        $permissions = $this->app->getAuthorizationPermissions();

        if ($permissions !== null) {
            $this->app->addServiceProvider(new AuthorizationTemplateServiceProvider($engine, $permissions));
        }

        if ($this->app->isAuthenticationAdded()) {
            $this->app->addServiceProvider(new AuthenticationTemplateServiceProvider($engine));
        }

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
        $this->app->markRivetAdded();

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
     * The first call logs a notice for each slot an extension took over, then
     * boots each extension. Later calls only return the application.
     *
     * @return Application
     */
    public function create(): Application
    {
        if (! $this->app->claimedSlots->markCreated()) {
            return $this->app;
        }

        $takeovers = $this->app->claimedSlots->takeovers();

        if ($takeovers !== []) {
            $logger = $this->app->get(LoggerInterface::class);

            if (! $logger instanceof LoggerInterface) {
                throw new ConfigurationException(
                    'The container did not return a LoggerInterface. Pass one to withLogger().'
                );
            }

            foreach ($takeovers as $takeover) {
                $logger->notice($takeover);
            }
        }

        foreach ($this->extensions as $extension) {
            $extension->boot($this->app);
        }

        return $this->app;
    }
}
