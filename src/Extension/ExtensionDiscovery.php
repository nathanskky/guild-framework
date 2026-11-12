<?php

declare(strict_types=1);

namespace Guild\Framework\Extension;

use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Guild\Framework\Exception\ConfigurationException;
use JsonException;

/**
 * Finds the installed Composer packages of type guild-extension and builds the
 * ApplicationExtension each names in extra.guild.extension.
 *
 * An extension required with require-dev is never discovered in a production
 * build: after composer install --no-dev, or composer dump-autoload --no-dev
 * over a development install.
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
        return self::fromInstalledData(InstalledVersions::getAllRawData(), self::composerAutoloads(...));
    }

    /**
     * The guild-extension packages in Composer's installed data, in the shape
     * InstalledVersions::getAllRawData() returns: one dataset per vendor
     * directory, the first naming a package winning.
     *
     * A dev package is skipped when its dataset was installed without dev
     * packages, or when nothing is autoloaded from its directory. The second
     * covers composer dump-autoload --no-dev over a development install, which
     * leaves the package in vendor/ and the dataset still marked as dev, but
     * drops the package from the autoloader, so building it would fail boot.
     * A package that is autoloaded but names a missing class is still an error.
     *
     * @param  array<mixed>  $datasets
     * @param  Closure(string): bool  $autoloads  Whether classes are autoloaded from inside a directory.
     */
    public static function fromInstalledData(array $datasets, Closure $autoloads): self
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
                    || (($version['dev_requirement'] ?? false) === true && (! $devInstalled || ! $autoloads($version['install_path'])))
                ) {
                    continue;
                }

                $installPaths[$package] = $version['install_path'];
            }
        }

        return new self($installPaths);
    }

    /**
     * Whether a registered Composer autoloader loads classes from inside
     * $directory, through its PSR-4 or PSR-0 directories, fallback directories
     * or class map. Composer writes paths through vendor/composer/.., so the
     * few directories are compared with dot segments resolved. The class map,
     * thousands of entries in an optimized autoloader, is compared as written,
     * against $directory as written and resolved: Composer writes a package's
     * install path and its class map entries the same way.
     */
    public static function composerAutoloads(string $directory): bool
    {
        $root = self::withoutDotSegments($directory) . '/';
        $asWritten = rtrim($directory, '/') . '/';

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $directories = [
                ...array_merge(...array_values($loader->getPrefixesPsr4())),
                ...array_merge(...array_values($loader->getPrefixes())),
                ...$loader->getFallbackDirsPsr4(),
                ...$loader->getFallbackDirs(),
            ];

            foreach ($directories as $candidate) {
                if (str_starts_with(self::withoutDotSegments($candidate) . '/', $root)) {
                    return true;
                }
            }

            foreach ($loader->getClassMap() as $file) {
                if (str_starts_with($file, $asWritten) || str_starts_with($file, $root)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * $path with . and .. segments resolved, without touching the filesystem.
     */
    private static function withoutDotSegments(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (! str_contains($path, '/.')) {
            return rtrim($path, '/');
        }

        $segments = [];

        foreach (explode('/', $path) as $index => $segment) {
            if ($segment === '..' && $segments !== [] && end($segments) !== '') {
                array_pop($segments);
            } elseif ($segment !== '.' && ($segment !== '' || $index === 0)) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
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
