<?php declare(strict_types=1);

namespace Shadow\Framework;

class ApplicationBuilder
{
    public function __construct(private Application $app)
    {}

    public function withRouting(?string $routesFilePath = null): self
    {
        $routesFilePath = match (true) {
            is_string($routesFilePath) => $routesFilePath,
            default => $this->app->get('path.base') . '/routes/routes.php'
        };

        $callable = require $routesFilePath;

        if (is_callable($callable)) {
            $callable($this->app->get(Router::class));
        }

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