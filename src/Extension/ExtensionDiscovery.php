<?php

declare(strict_types=1);

namespace Guild\Framework\Extension;

use Composer\InstalledVersions;
use Guild\Framework\Exception\ConfigurationException;
use JsonException;

/**
 * Finds the installed Composer packages of type guild-extension and builds the
 * ApplicationExtension each names in extra.guild.extension.
 *
 * A --no-dev install leaves out dev packages, so an extension required with
 * require-dev is never discovered in production.
 *
 * @internal
 */
final readonly class ExtensionDiscovery
{
    public const string PACKAGE_TYPE = 'guild-extension';

    /**
     * @param  array<string, string>  $installPaths  Package name => install path.
     */
    public function __construct(private array $installPaths)
    {
    }

    /**
     * The guild-extension packages Composer installed.
     */
    public static function fromComposer(): self
    {
        return self::fromInstalledData(InstalledVersions::getAllRawData());
    }

    /**
     * The guild-extension packages in Composer's installed data, in the shape
     * InstalledVersions::getAllRawData() returns: one dataset per vendor
     * directory, the first naming a package winning.
     *
     * A dev package is skipped when its dataset was installed without dev
     * packages. After composer dump-autoload --no-dev the package is still in
     * vendor/ and still listed, but its classes are not autoloaded, so
     * building it would fail production boot.
     *
     * @param  array<mixed>  $datasets
     */
    public static function fromInstalledData(array $datasets): self
    {
        $installPaths = [];

        foreach ($datasets as $dataset) {
            $root = is_array($dataset) ? $dataset['root'] ?? null : null;
            $versions = is_array($dataset) ? $dataset['versions'] ?? null : null;
            $devInstalled = is_array($root) && ($root['dev'] ?? false) === true;

            foreach (is_array($versions) ? $versions : [] as $package => $version) {
                if (
                    ! is_string($package)
                    || isset($installPaths[$package])
                    || ! is_array($version)
                    || ($version['type'] ?? null) !== self::PACKAGE_TYPE
                    || ! is_string($version['install_path'] ?? null)
                    || (($version['dev_requirement'] ?? false) === true && ! $devInstalled)
                ) {
                    continue;
                }

                $installPaths[$package] = $version['install_path'];
            }
        }

        return new self($installPaths);
    }

    /**
     * One extension per package, in package name order.
     *
     * @return list<ApplicationExtension>
     */
    public function extensions(): array
    {
        $installPaths = $this->installPaths;
        ksort($installPaths, SORT_STRING);

        $extensions = [];

        foreach ($installPaths as $package => $path) {
            $extensions[] = $this->instantiate($package, $this->extensionClass($package, $path));
        }

        return $extensions;
    }

    /**
     * Composer\InstalledVersions does not expose extra, so the package's own
     * composer.json is read.
     */
    private function extensionClass(string $package, string $path): string
    {
        $file = rtrim($path, '/\\') . '/composer.json';
        $contents = is_file($file) ? file_get_contents($file) : false;

        try {
            $composer = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $composer = null;
        }

        if (! is_array($composer)) {
            throw new ConfigurationException(sprintf(
                'Package %s has type %s, but %s is missing or is not valid JSON. '
                . 'Reinstall the package with composer install.',
                $package,
                self::PACKAGE_TYPE,
                $file,
            ));
        }

        $extra = $composer['extra'] ?? null;
        $guild = is_array($extra) ? $extra['guild'] ?? null : null;
        $class = is_array($guild) ? $guild['extension'] ?? null : null;

        if (! is_string($class) || $class === '') {
            throw new ConfigurationException(sprintf(
                'Package %s has type %s but no extra.guild.extension class in its composer.json. '
                . 'Add the class name of its ApplicationExtension there.',
                $package,
                self::PACKAGE_TYPE,
            ));
        }

        return $class;
    }

    private function instantiate(string $package, string $class): ApplicationExtension
    {
        if (! class_exists($class)) {
            throw new ConfigurationException(sprintf(
                'Package %s names %s as its extension, but that class does not exist.',
                $package,
                $class,
            ));
        }

        if (! is_subclass_of($class, ApplicationExtension::class)) {
            throw new ConfigurationException(sprintf(
                'Package %s names %s as its extension, but that class does not implement %s.',
                $package,
                $class,
                ApplicationExtension::class,
            ));
        }

        return new $class();
    }
}
