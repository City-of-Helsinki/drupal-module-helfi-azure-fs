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
   * Tests that overrides are only applied when applicable.
   */
  public function testLoadOverrides(): void {
    // Blob storage disabled.
    $this->assertEquals(
      [],
      $this->getSut(['use_blob_storage' => FALSE])
        ->loadOverrides(['system.file', 'media.type.remote_video']),
    );

    // Blob storage enabled, but no matching config being loaded.
    $this->assertEquals(
      [],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['media.type.image']),
    );

    // Blob storage enabled, only 'system.file' is being loaded.
    $this->assertEquals(
      [
        'system.file' => [
          'default_scheme' => 'azure',
        ],
      ],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['system.file']),
    );

    // Blob storage enabled, only 'media.type.remote_video' is being loaded.
    $this->assertEquals(
      [
        'media.type.remote_video' => [
          'source_configuration' => [
            'thumbnails_directory' => 'azure://oembed_thumbnails',
          ],
        ],
      ],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['media.type.remote_video']),
    );

    // Blob storage enabled, both are being loaded.
    $this->assertEquals(
      [
        'system.file' => [
          'default_scheme' => 'azure',
        ],
        'media.type.remote_video' => [
          'source_configuration' => [
            'thumbnails_directory' => 'azure://oembed_thumbnails',
          ],
        ],
      ],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['system.file', 'media.type.remote_video']),
    );
  }

  /**
   * Tests getCacheSuffix().
   */
  public function testGetCacheSuffix(): void {
    $this->assertEquals(
      'azure_fs_config_overrides',
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
      ['config:helfi_azure_fs.settings'],
      $sut->getCacheableMetadata('system.file')->getCacheTags(),
    );
    $this->assertEquals(
      ['config:helfi_azure_fs.settings'],
      $sut->getCacheableMetadata('media.type.remote_video')->getCacheTags(),
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
