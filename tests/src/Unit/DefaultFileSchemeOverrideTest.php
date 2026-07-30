<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Config\DefaultFileSchemeOverride;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DefaultFileSchemeOverride.
 */
#[Group('helfi_azure_fs')]
class DefaultFileSchemeOverrideTest extends UnitTestCase {

  /**
   * Tests that the default file scheme is only overridden when applicable.
   */
  public function testLoadOverrides(): void {
    // Blob storage disabled.
    $this->assertEquals(
      [],
      $this->getSut(['use_blob_storage' => FALSE])
        ->loadOverrides(['system.file']),
    );

    // Blob storage enabled, but config not being loaded.
    $this->assertEquals(
      [],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['media.type.image']),
    );

    // Blob storage enabled.
    $this->assertEquals(
      [
        'system.file' => [
          'default_scheme' => 'azure',
        ],
      ],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['system.file']),
    );
  }

  /**
   * Tests getCacheSuffix().
   */
  public function testGetCacheSuffix(): void {
    $this->assertEquals(
      'system_file_default_scheme_override',
      $this->getSut([])->getCacheSuffix(),
    );
  }

  /**
   * Tests createConfigObject().
   */
  public function testCreateConfigObject(): void {
    $this->assertNull($this->getSut([])->createConfigObject('system.file'));
  }

  /**
   * Tests getCacheableMetadata().
   */
  public function testGetCacheableMetadata(): void {
    $sut = $this->getSut([]);

    $this->assertEquals(
      ['config:system.file'],
      $sut->getCacheableMetadata('system.file')->getCacheTags(),
    );
    $this->assertEquals(
      [],
      $sut->getCacheableMetadata('media.type.image')->getCacheTags(),
    );
  }

  /**
   * Gets the SUT.
   *
   * @param array<mixed> $settings
   *   The 'helfi_azure_fs.settings' configuration data.
   *
   * @return \Drupal\helfi_azure_fs\Config\DefaultFileSchemeOverride
   *   The SUT.
   */
  private function getSut(array $settings): DefaultFileSchemeOverride {
    $config = $this->getConfigFactoryStub(['helfi_azure_fs.settings' => $settings]);

    return new DefaultFileSchemeOverride($config);
  }

}
