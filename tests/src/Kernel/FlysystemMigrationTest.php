<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystem;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests the migration from the Flysystem module.
 *
 * Can be removed once the Flysystem module is uninstalled everywhere.
 *
 * @see helfi_azure_fs_update_90401()
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class FlysystemMigrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'image',
    'key',
    'flysystem',
    'helfi_azure_fs',
  ];

  /**
   * Whether the blob storage is configured.
   */
  private bool $configured = TRUE;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $this->setSetting('helfi_azure_fs', $this->configured ? [
      'name' => 'account',
      'container' => 'container',
      'token' => 'token',
      'public_url_base' => 'https://account.blob.core.windows.net/container',
    ] : NULL);
    parent::register($container);
  }

  /**
   * Gets the default file scheme.
   *
   * @return string|null
   *   The default scheme.
   */
  private function getDefaultScheme() : ?string {
    // KernelTestBase overrides the default scheme in $GLOBALS['config'], which
    // takes precedence over the module overrides.
    unset($GLOBALS['config']['system.file']['default_scheme']);
    $configFactory = \Drupal::configFactory();
    $configFactory->reset('system.file');

    return $configFactory->get('system.file')->get('default_scheme');
  }

  /**
   * Checks whether the given route exists.
   *
   * @param string $name
   *   The route name.
   *
   * @return bool
   *   TRUE if the route exists.
   */
  private function routeExists(string $name) : bool {
    try {
      \Drupal::service('router.route_provider')->getRouteByName($name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * Tests that the Flysystem module is replaced until it's uninstalled.
   */
  public function testFlysystemIsReplaced() : void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('flysystem'));

    $this->assertInstanceOf(AzureStreamWrapper::class, $this->container->get('stream_wrapper_manager')->getViaScheme('azure'));
    $this->assertFalse($this->container->has('stream_wrapper.flysystem.azure'));

    // Flysystem's file system is replaced with the core one.
    $fileSystem = $this->container->get('file_system');
    $this->assertInstanceOf(AzureFileSystem::class, $fileSystem);
    $this->assertSame(FileSystem::class, get_class((new \ReflectionProperty($fileSystem, 'decorated'))->getValue($fileSystem)));

    $this->assertFalse($this->routeExists('image.style_flysystem.azure'));
    $this->assertTrue($this->routeExists('helfi_azure_fs.image_style'));

    $this->assertSame(
      'https://account.blob.core.windows.net/container/file.txt',
      $this->container->get('file_url_generator')->generateAbsoluteString('azure://file.txt'),
    );
    $this->assertSame('azure', $this->getDefaultScheme());
  }

  /**
   * Tests that the core file system is restored without the blob storage.
   */
  public function testNotConfigured() : void {
    $this->configured = FALSE;
    $this->container->get('kernel')->rebuildContainer();

    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('flysystem'));
    $this->assertFalse(\Drupal::service('stream_wrapper_manager')->isValidScheme('azure'));
    $fileSystem = \Drupal::service('file_system');
    $this->assertSame(FileSystem::class, get_class((new \ReflectionProperty($fileSystem, 'decorated'))->getValue($fileSystem)));
  }

  /**
   * Tests that the update hook uninstalls the Flysystem module.
   */
  public function testUpdateHook() : void {
    $this->container->get('module_handler')->loadInclude('helfi_azure_fs', 'install');

    $this->assertStringContainsString('Uninstalled the Flysystem module', (string) helfi_azure_fs_update_90401());
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('flysystem'));
    $this->assertInstanceOf(AzureStreamWrapper::class, \Drupal::service('stream_wrapper_manager')->getViaScheme('azure'));

    // Running it again does nothing.
    $this->assertStringContainsString('is not installed', (string) helfi_azure_fs_update_90401());
  }

}
