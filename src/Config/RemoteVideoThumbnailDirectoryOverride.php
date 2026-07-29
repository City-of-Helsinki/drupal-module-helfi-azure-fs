<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageInterface;

/**
 * Overrides the remote video thumbnail directory when using Azure blob storage.
 */
final class RemoteVideoThumbnailDirectoryOverride implements ConfigFactoryOverrideInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Loads the overrides.
   *
   * @param array<mixed> $names
   *
   * @return array<mixed>
   *   The configuration override.
   */
  public function loadOverrides($names): array {
    $overrides = [];

    if (in_array('media.type.remote_video', $names, TRUE) && $this->useBlobStorage()) {
      $overrides['media.type.remote_video']['source_configuration']['thumbnails_directory'] = 'azure://oembed_thumbnails';
    }

    return $overrides;
  }

  /**
   * Checks whether Azure blob storage is enabled.
   *
   * @return bool
   *   TRUE if Azure blob storage should be used.
   */
  private function useBlobStorage(): bool {
    return (bool) $this->configFactory->get('helfi_azure_fs.settings')->get('use_blob_storage');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'remote_video_thumbnail_directory_override';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION): ?StorableConfigBase {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();

    if ($name === 'media.type.remote_video') {
      $metadata->addCacheTags(['config:helfi_azure_fs.settings']);
    }

    return $metadata;
  }

}
