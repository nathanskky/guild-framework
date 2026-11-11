<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Closure;
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Extension\ApplicationExtension;

/**
 * An extension for tests: records each configure() and boot() call in a
 * static list, which tests reset in setUp(), and optionally runs a closure
 * against the builder in configure().
 */
final class RecordingExtension implements ApplicationExtension
{
    /** @var list<string> */
    public static array $calls = [];

    /**
     * @param  (Closure(ApplicationBuilder): void)|null  $configure
     */
    public function __construct(private ?Closure $configure = null)
    {
    }

    #[\Override]
    public function configure(ApplicationBuilder $builder): void
    {
        self::$calls[] = 'configure';

        if ($this->configure !== null) {
            ($this->configure)($builder);
        }
    }

    #[\Override]
    public function boot(Application $app): void
    {
        self::$calls[] = 'boot';
    }
}
