<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Extension\ApplicationExtension;
use Guild\Framework\Test\Authentication\Support\FakeSignIn;

/**
 * An extension for tests that claims each slot it is given an argument for
 * and, at boot, binds the instances it was given under their own class
 * names, so the container hands back the test's own objects.
 */
final readonly class ClaimingExtension implements ApplicationExtension
{
    public function __construct(
        private ?FakeSignIn $signIn = null,
        private ?ArrayGroupDirectory $directory = null,
        private ?string $systemAdminGroup = null,
    ) {
    }

    #[\Override]
    public function configure(ApplicationBuilder $builder): void
    {
        if ($this->signIn !== null) {
            $builder->useSignInMethod(FakeSignIn::class);
        }

        if ($this->directory !== null) {
            $builder->useGroupDirectory(ArrayGroupDirectory::class);
        }

        if ($this->systemAdminGroup !== null) {
            $builder->useSystemAdminGroup($this->systemAdminGroup);
        }
    }

    #[\Override]
    public function boot(Application $app): void
    {
        if ($this->signIn !== null) {
            $app->addShared(FakeSignIn::class, $this->signIn);
        }

        if ($this->directory !== null) {
            $app->addShared(ArrayGroupDirectory::class, $this->directory);
        }
    }
}
