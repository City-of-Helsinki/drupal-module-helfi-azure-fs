<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Flysystem\Adapter;

use Drupal\helfi_azure_fs\AzureBlobStorageAdapter as AzureBlobStorageAdapterBase;

/**
 * Drupal specific overrides.
 */
class AzureBlobStorageAdapter extends AzureBlobStorageAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function getMetadata($path): bool|array {
    $metadata = parent::getMetadata($path);

    if ($metadata === FALSE && in_array($path, ['css', 'js'])) {
      return [
        'type' => 'dir',
        'path' => $path,
      ];
    }
    return $metadata;
  }

}
