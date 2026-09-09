<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Drush\Commands;

/**
 * Provides a helper to resolve the configured Blob storage scheme.
 *
 * Requires the using class to have a $configFactory property of type
 * \Drupal\Core\Config\ConfigFactoryInterface.
 */
trait AzureStorageSchemeTrait {

  /**
   * Gets the storage scheme, if Blob storage is enabled.
   *
   * @return string|null
   *   The storage scheme, or NULL if Blob storage is not enabled.
   */
  private function getScheme() : ?string {
    $config = $this->configFactory->get('helfi_azure_fs.settings');

    if (!$config->get('use_blob_storage')) {
      return NULL;
    }
    return $config->get('storage_scheme') ?: 'azure';
  }

}
