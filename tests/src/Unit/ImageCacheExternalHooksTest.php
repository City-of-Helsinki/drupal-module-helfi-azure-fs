<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Hook\ImageCacheExternalHooks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests Imagecache external integration.
 */
#[Group('helfi_azure_fs')]
class ImageCacheExternalHooksTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Tests 'imagecache_external_destination_alter' hook implementation.
   */
  public function testSchemeAlter(): void {
    $alter = [
      'scheme' => 'public',
    ];
    $this->getSut([])
      ->alterDestination($alter, []);

    $this->assertEquals('public', $alter['scheme']);

    $this->getSut(['use_blob_storage' => FALSE])
      ->alterDestination($alter, []);

    $this->assertEquals('public', $alter['scheme']);

    $this->getSut(['use_blob_storage' => TRUE])
      ->alterDestination($alter, []);
    $this->assertEquals('azure', $alter['scheme']);
  }

  /**
   * Gets the SUT.
   *
   * @param array<mixed> $config
   *   The configuration.
   *
   * @return \Drupal\helfi_azure_fs\Hook\ImageCacheExternalHooks
   *   The SUT.
   */
  public function getSut(array $config): ImageCacheExternalHooks {
    return new ImageCacheExternalHooks(
      fn() => $this->getConfigFactoryStub(['helfi_azure_fs.settings' => $config]),
    );
  }

}
