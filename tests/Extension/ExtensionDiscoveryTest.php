<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension;

use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Extension\ExtensionDiscovery;
use Guild\Framework\Test\Extension\Support\QuietExtension;
use Guild\Framework\Test\Extension\Support\RecordingExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ExtensionDiscovery::class)]
#[UsesClass(RecordingExtension::class)]
#[UsesClass(QuietExtension::class)]
final class ExtensionDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guild-framework-extensions-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/composer.json') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            rmdir($directory);
        }

        rmdir($this->root);
    }

    public function testAnInstalledExtensionIsInstantiated(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => RecordingExtension::class]]]);

        $extensions = new ExtensionDiscovery(['acme/recording' => $path])->extensions();

        self::assertCount(1, $extensions, 'the one installed package gives one extension');
        self::assertInstanceOf(RecordingExtension::class, $extensions[0], 'built from the class its composer.json names');
    }

    public function testExtensionsAreReturnedInPackageNameOrder(): void
    {
        $recording = $this->package(['extra' => ['guild' => ['extension' => RecordingExtension::class]]]);
        $quiet = $this->package(['extra' => ['guild' => ['extension' => QuietExtension::class]]]);

        $extensions = new ExtensionDiscovery(['zeta/recording' => $recording, 'alpha/quiet' => $quiet])->extensions();

        self::assertCount(2, $extensions, 'both packages give an extension');
        self::assertInstanceOf(QuietExtension::class, $extensions[0], 'alpha/quiet comes first');
        self::assertInstanceOf(RecordingExtension::class, $extensions[1], 'zeta/recording comes second');
    }

    public function testADevExtensionIsSkippedWhenDevPackagesAreNotInstalled(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => 'Acme\DevTools\NotAutoloadedExtension']]]);

        $discovery = ExtensionDiscovery::fromInstalledData([
            self::dataset(dev: false, versions: ['acme/dev-tools' => self::installed(ExtensionDiscovery::PACKAGE_TYPE, $path, devRequirement: true)]),
        ]);

        self::assertSame([], $discovery->extensions(), 'after dump-autoload --no-dev the package is still on disk, but its class is not loadable');
    }

    public function testTheSameUnloadableExtensionFailsWhenDevPackagesAreInstalled(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => 'Acme\DevTools\NotAutoloadedExtension']]]);

        $discovery = ExtensionDiscovery::fromInstalledData([
            self::dataset(dev: true, versions: ['acme/dev-tools' => self::installed(ExtensionDiscovery::PACKAGE_TYPE, $path, devRequirement: true)]),
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('names Acme\DevTools\NotAutoloadedExtension as its extension, but that class does not exist.');

        $discovery->extensions();
    }

    public function testADevExtensionIsFoundWhenDevPackagesAreInstalled(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => RecordingExtension::class]]]);

        $discovery = ExtensionDiscovery::fromInstalledData([
            self::dataset(dev: true, versions: ['acme/dev-tools' => self::installed(ExtensionDiscovery::PACKAGE_TYPE, $path, devRequirement: true)]),
        ]);

        self::assertCount(1, $discovery->extensions(), 'a development install discovers its require-dev extensions');
    }

    public function testPackagesOfOtherTypesAreIgnored(): void
    {
        $discovery = ExtensionDiscovery::fromInstalledData([
            self::dataset(dev: true, versions: [
                'acme/library' => self::installed('library', $this->root . '/library'),
                'acme/virtual' => ['pretty_version' => '1.0.0', 'provided' => ['1.0.0']],
            ]),
        ]);

        self::assertSame([], $discovery->extensions(), 'only guild-extension packages with an install path are extensions');
    }

    public function testAPackageListedInTwoDatasetsIsFoundOnce(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => RecordingExtension::class]]]);
        $entry = ['acme/recording' => self::installed(ExtensionDiscovery::PACKAGE_TYPE, $path)];

        $discovery = ExtensionDiscovery::fromInstalledData([
            self::dataset(dev: true, versions: $entry),
            self::dataset(dev: true, versions: ['acme/recording' => self::installed(ExtensionDiscovery::PACKAGE_TYPE, $this->root . '/elsewhere')]),
        ]);

        self::assertCount(1, $discovery->extensions(), 'a package seen by two vendor directories is built once, from the first');
    }

    public function testAPackageWithoutAnExtensionClassIsRejected(): void
    {
        $path = $this->package(['extra' => ['guild' => []]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Package acme/empty has type guild-extension but no extra.guild.extension class in its composer.json. '
            . 'Add the class name of its ApplicationExtension there.'
        );

        new ExtensionDiscovery(['acme/empty' => $path])->extensions();
    }

    public function testAClassThatIsNotAnExtensionIsRejected(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => stdClass::class]]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Package acme/wrong names stdClass as its extension, but that class does not implement '
            . 'Guild\Framework\Extension\ApplicationExtension.'
        );

        new ExtensionDiscovery(['acme/wrong' => $path])->extensions();
    }

    public function testAMissingClassIsRejected(): void
    {
        $path = $this->package(['extra' => ['guild' => ['extension' => 'Acme\NoSuchExtension']]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Package acme/missing names Acme\NoSuchExtension as its extension, but that class does not exist.'
        );

        new ExtensionDiscovery(['acme/missing' => $path])->extensions();
    }

    public function testAnUnreadableComposerJsonIsRejected(): void
    {
        $path = $this->root . '/broken';
        mkdir($path);
        file_put_contents($path . '/composer.json', '{not json');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Package acme/broken has type guild-extension, but ' . $path . '/composer.json');

        new ExtensionDiscovery(['acme/broken' => $path])->extensions();
    }

    public function testThisRepositoryHasNoInstalledExtensions(): void
    {
        self::assertSame(
            [],
            ExtensionDiscovery::fromComposer()->extensions(),
            'guild/framework installs no guild-extension package',
        );
    }

    /**
     * Write a package directory whose composer.json holds $composer.
     *
     * @param  array<string, mixed>  $composer
     */
    private function package(array $composer): string
    {
        $path = $this->root . '/' . bin2hex(random_bytes(4));
        mkdir($path);
        file_put_contents($path . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param  array<string, array<string, mixed>>  $versions
     * @return array<string, mixed>
     */
    private static function dataset(bool $dev, array $versions): array
    {
        return ['root' => ['name' => 'acme/app', 'type' => 'project', 'dev' => $dev], 'versions' => $versions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function installed(string $type, string $installPath, bool $devRequirement = false): array
    {
        return ['pretty_version' => '1.0.0', 'type' => $type, 'install_path' => $installPath, 'dev_requirement' => $devRequirement];
    }
}
