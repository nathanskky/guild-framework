<?php declare(strict_types=1);

namespace Shadow\Framework;

use Shadow\Access\Authentication\AuthenticationServiceProvider;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Container\Argument\Literal;
use League\Container\Container;
use Psr\Http\Message\ServerRequestInterface;

class Application extends Container
{
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
        $this->registerBaseServiceProviders();
    }


    public static function configure(string $basePath): ApplicationBuilder
    {
        return new ApplicationBuilder(new static($basePath));
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

    /**
     * Bind all the application paths in the container.
     *
     * @return void
     */
    private function bindPathsInContainer(): void
    {
        // TODO
        $this->add('path.base', new Literal\StringArgument($this->basePath));
        $this->add('path.config', new Literal\StringArgument($this->basePath . '/config'));
    }

    private function registerBaseServiceProviders(): void
    {
        $this->addServiceProvider(new AuthenticationServiceProvider());
    }

    private function registerBaseBindings(): void
    {
        $this->addShared(ServerRequestInterface::class, [ServerRequestFactory::class, 'fromGlobals']);
        $this->addShared(Router::class, function () {
            $strategy = new \League\Route\Strategy\ApplicationStrategy;
            $strategy->setContainer($this);
            return new Router()->setStrategy($strategy);
        });
        $this->addShared(SapiEmitter::class);
    }

    public function run(): void
    {
        $router = $this->get(Router::class);
        $response = $router->dispatch($this->get(ServerRequestInterface::class));
        $emitter = $this->get(SapiEmitter::class);
        $emitter->emit($response);
    }
}
