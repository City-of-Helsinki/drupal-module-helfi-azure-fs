<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\helfi_azure_fs\Plugin\Flysystem\Adapter\Azure;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Azure adapter driver integration with Flysystem.
 *
 * These tests attempt to catch if Flysystem updates break the features we
 * rely on.
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class FlysystemRoutesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'key',
    'system',
    'flysystem',
    'helfi_azure_fs',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->setSetting('flysystem', [
      'azure' => [
        'driver' => 'helfi_azure',
        'public_url_base' => 'https://mock-name.blob.core.windows.net/mock-container',
        'config' => [
          'name' => 'mock-name',
          'token' => 'mock-token',
          'container' => 'mock-container',
          'endpointSuffix' => 'core.windows.net',
          'protocol' => 'https',
        ],
      ],
    ]);
  }

  /**
   * Tests that the driver is discovered and used for the 'azure' scheme.
   */
  public function testAdapterDriver() : void {
    /** @var \Drupal\flysystem\Adapter\AdapterDriverPluginManager $manager */
    $manager = $this->container->get('plugin.manager.flysystem.adapter_driver');
    $this->assertTrue($manager->hasDefinition('helfi_azure'));

    /** @var \Drupal\flysystem\Adapter\FilesystemFactoryInterface $factory */
    $factory = $this->container->get('flysystem.filesystem_factory');
    $this->assertInstanceOf(Azure::class, $factory->getDriver('azure'));
    $this->assertInstanceOf(Filesystem::class, $factory->getFilesystem('azure'));
    $this->assertEquals('https://mock-name.blob.core.windows.net/mock-container', $factory->getDefinition('azure')->publicUrlBase);
  }

  /**
   * Tests that the legacy image style route is still available.
   */
  public function testImageStyleRoute() : void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('flysystem.image_style');

    $this->assertEquals('/_flysystem/styles/{image_style}/{scheme}', $route->getPath());
    $this->assertEquals('azure', $route->getDefault('required_derivative_scheme'));
  }

}
