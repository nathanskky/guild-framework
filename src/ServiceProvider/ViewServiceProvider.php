<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\TemplateEngine;
use Guild\Framework\View;
use Latte\Engine;
use Latte\Loaders\FileLoader;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class ViewServiceProvider extends AbstractServiceProvider
{
    public function __construct(private readonly TemplateEngine $templateEngine)
    {
    }

    public function provides(string $id): bool
    {
        $services = [
            View::class
        ];

        if ($this->templateEngine === TemplateEngine::Twig) {
            $services[] = Environment::class;
            $services[] = FilesystemLoader::class;
        }

        if ($this->templateEngine === TemplateEngine::Latte) {
            $services[] = Engine::class;
            $services[] = FileLoader::class;
        }

        return in_array($id, $services);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        $container->addShared(View::class, function () use ($container) {
            $engine = match ($this->templateEngine) {
                TemplateEngine::Twig => $container->get(Environment::class),
                TemplateEngine::Latte => $container->get(Engine::class),
            };
            return new View($engine);
        });

        if ($this->templateEngine === TemplateEngine::Twig) {
            $container->addShared(
                FilesystemLoader::class,
                fn () =>
                new FilesystemLoader($container->getPath('base') . '/templates')
            );
            $container->addShared(
                Environment::class,
                fn () =>
                new Environment($container->get(FilesystemLoader::class))
            );
        }

        if ($this->templateEngine === TemplateEngine::Latte) {
            $container->addShared(
                FileLoader::class,
                fn () =>
                new FileLoader($container->getPath('base') . '/templates')
            );
            $container->addShared(
                Engine::class,
                fn () =>
                new Engine()->setLoader($container->get(FileLoader::class))
            );
        }
    }
}
