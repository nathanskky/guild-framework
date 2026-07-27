<?php declare(strict_types=1);

namespace Guild\Framework;

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use League\Container\Argument\Literal\StringArgument;
use League\Container\Container;
use League\Route\Strategy\ApplicationStrategy;
use Psr\Http\Message\ServerRequestInterface;

final class Application extends Container
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
            $strategy = new ApplicationStrategy;
            $strategy->setContainer($this);
            return new Router()->setStrategy($strategy);
        });
        $this->addShared(SapiEmitter::class);
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
