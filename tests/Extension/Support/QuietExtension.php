<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Extension\ApplicationExtension;

/**
 * An extension for tests that does nothing: a second class, so discovery
 * order can be told apart from RecordingExtension.
 */
final class QuietExtension implements ApplicationExtension
{
    #[\Override]
    public function configure(ApplicationBuilder $builder): void
    {
    }

    #[\Override]
    public function boot(Application $app): void
    {
    }
}
