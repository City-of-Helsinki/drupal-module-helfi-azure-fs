<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageInterface;

/**
 * Overrides the default file scheme when using Azure blob storage.
 */
final class DefaultFileSchemeOverride implements ConfigFactoryOverrideInterface {

  private const array CONFIGURATION = [
    'system.file' => ['default_scheme' => 'azure'],
    'media.type.remote_video' => [
      'source_configuration' => [
        'thumbnails_directory' => 'azure://oembed_thumbnails',
      ],
    ],
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Checks whether Azure blob storage is enabled.
   *
   * @return bool
   *   TRUE if Azure blob storage should be used.
   */
  protected function useBlobStorage(): bool {
    return (bool) $this->configFactory->get('helfi_azure_fs.settings')->get('use_blob_storage');
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION): ?StorableConfigBase {
    return NULL;
  }

  /**
   * Loads the overrides.
   *
   * @param array<mixed> $names
   *   The config names.
   *
   * @return array<mixed>
   *   The configuration override.
   */
  public function loadOverrides($names): array {
    if (!$this->useBlobStorage()) {
      return [];
    }
    $overrides = [];

    foreach (self::CONFIGURATION as $name => $value) {
      if (!in_array($name, $names, TRUE)) {
        continue;
      }
      $overrides[$name] = $value;
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'azure_fs_config_overrides';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();

    if (isset(self::CONFIGURATION[$name])) {
      $metadata->addCacheTags(['config:helfi_azure_fs.settings']);
    }
    return $metadata;
  }

}
