<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Config;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Overrides the default file scheme when using Azure blob storage.
 */
final class DefaultFileSchemeOverride extends ConfigFactoryOverrideBase {

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
    $overrides = [];

    if (in_array('system.file', $names, TRUE) && $this->useBlobStorage()) {
      $overrides['system.file']['default_scheme'] = 'azure';
    }

    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'system_file_default_scheme_override';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();

    if ($name === 'system.file') {
      $metadata->addCacheTags(['config:system.file']);
    }

    return $metadata;
  }

}
