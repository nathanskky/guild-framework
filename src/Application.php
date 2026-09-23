<?php

declare(strict_types=1);

namespace Guild\Framework;

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Container\Argument\Literal\StringArgument;
use League\Container\Container;
use League\Route\Strategy\ApplicationStrategy;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class Application extends Container
{
    /**
     * The template engine this application was configured with, if any.
     *
     * Remembered here rather than inferred from the container: with autowiring enabled a
     * reflection delegate reports almost any class as available, so asking the container
     * whether an engine is bound would answer yes even when none was chosen.
     */
    private ?TemplateEngine $templateEngine = null;

    /**
     * Whether addAuthentication() has run. Remembered for the same reason as
     * $templateEngine: the container cannot be asked.
     */
    private bool $authenticationAdded = false;

    /**
     * The permission enum given to addAuthorization(), or null if it has not
     * been called. Lets addTemplateEngine() add the template functions when it
     * is called second.
     *
     * @var class-string<Authorization\Permission>|null
     */
    private ?string $authorizationPermissions = null;

    /**
     * The base path for the application installation.
     *
     * @var string
     */
    protected string $basePath;

    public function __construct(?string $basePath = null)
    {
        parent::__construct();

        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
    }


    public static function configure(string $basePath): ApplicationBuilder
    {
        return new ApplicationBuilder(new self($basePath));
    }

    /**
     * Set the base path for the application.
     *
     * @param  string  $basePath
     * @return $this
     */
    public function setBasePath(string $basePath): Application
    {
        $this->basePath = rtrim($basePath, '\/');

        $this->bindPathsInContainer();

        return $this;
    }

    public function getPath(string $resource): ?string
    {
        /** @var ?string $path */
        $path = $this->get('path.' . strtolower($resource));

        return is_string($path) ? $path : null;
    }

    /**
     * Bind all the application paths in the container.
     *
     * @return void
     */
    private function bindPathsInContainer(): void
    {
        // TODO
        $this->add('path.base', new StringArgument($this->basePath));
        $this->add('path.config', new StringArgument($this->basePath . '/config'));
    }

    private function registerBaseBindings(): void
    {
        $this->addShared(ServerRequestInterface::class, [ServerRequestFactory::class, 'fromGlobals']);
        $this->addShared(Router::class, function () {
            $strategy = new ApplicationStrategy();
            $strategy->setContainer($this);
            return new Router()->setStrategy($strategy);
        });
        $this->addShared(SapiEmitter::class);
        $this->addShared(LoggerInterface::class, static fn (): LoggerInterface => new Logger(
            'framework',
            [new ErrorLogHandler()],
            [new PsrLogMessageProcessor()],
        ));
    }

    public function setTemplateEngine(TemplateEngine $engine): void
    {
        $this->templateEngine = $engine;
    }

    public function getTemplateEngine(): ?TemplateEngine
    {
        return $this->templateEngine;
    }

    public function markAuthenticationAdded(): void
    {
        $this->authenticationAdded = true;
    }

    public function isAuthenticationAdded(): bool
    {
        return $this->authenticationAdded;
    }

    /**
     * @param  class-string<Authorization\Permission>  $permissionEnum
     */
    public function setAuthorizationPermissions(string $permissionEnum): void
    {
        $this->authorizationPermissions = $permissionEnum;
    }

    /**
     * @return class-string<Authorization\Permission>|null
     */
    public function getAuthorizationPermissions(): ?string
    {
        return $this->authorizationPermissions;
    }

    public function run(): void
    {
        /** @var Router $router */
        $router = $this->get(Router::class);

        /** @var SapiEmitter $emitter */
        $emitter = $this->get(SapiEmitter::class);

        /** @var ServerRequestInterface $request */
        $request = $this->get(ServerRequestInterface::class);

        $response = $router->dispatch($request);

        $emitter->emit($response);
    }
}
