<?php

declare(strict_types=1);

namespace Guild\Framework\Extension;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;

/**
 * A package that adds to the application when installed.
 *
 * Installed Composer packages of type guild-extension name their
 * implementation in extra.guild.extension; ExtensionDiscovery finds them.
 */
interface ApplicationExtension
{
    /** Runs at the start of Application::configure(), before the app's own add*() calls. */
    public function configure(ApplicationBuilder $builder): void;

    /** Runs at the end of create(), with the container ready. */
    public function boot(Application $app): void;
}
