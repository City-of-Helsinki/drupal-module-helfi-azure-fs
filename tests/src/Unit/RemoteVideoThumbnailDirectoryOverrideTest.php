<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\Config\StorageInterface;
use Drupal\helfi_azure_fs\Config\RemoteVideoThumbnailDirectoryOverride;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests RemoteVideoThumbnailDirectoryOverride.
 */
#[Group('helfi_azure_fs')]
class RemoteVideoThumbnailDirectoryOverrideTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Tests that the thumbnails directory is only overridden when applicable.
   */
  public function testLoadOverrides(): void {
    // Blob storage disabled.
    $this->assertEquals(
      [],
      $this->getSut(['use_blob_storage' => FALSE])
        ->loadOverrides(['media.type.remote_video']),
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
        'media.type.remote_video' => [
          'source_configuration' => [
            'thumbnails_directory' => 'azure://oembed_thumbnails',
          ],
        ],
      ],
      $this->getSut(['use_blob_storage' => TRUE])
        ->loadOverrides(['media.type.remote_video']),
    );
  }

  /**
   * Tests getCacheSuffix().
   */
  public function testGetCacheSuffix(): void {
    $this->assertEquals(
      'remote_video_thumbnail_directory_override',
      $this->getSut([])->getCacheSuffix(),
    );
  }

  /**
   * Tests createConfigObject().
   */
  public function testCreateConfigObject(): void {
    $this->assertNull($this->getSut([])->createConfigObject('media.type.remote_video'));
  }

  /**
   * Tests getCacheableMetadata().
   */
  public function testGetCacheableMetadata(): void {
    $sut = $this->getSut([]);

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
   * @return \Drupal\helfi_azure_fs\Config\RemoteVideoThumbnailDirectoryOverride
   *   The SUT.
   */
  private function getSut(array $settings): RemoteVideoThumbnailDirectoryOverride {
    $config = $this->getConfigFactoryStub(['helfi_azure_fs.settings' => $settings]);

    return new RemoteVideoThumbnailDirectoryOverride($config);
  }

}
