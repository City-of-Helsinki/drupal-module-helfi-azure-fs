<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs\Flysystem;

use Drupal\flysystem_azure\Flysystem\Adapter\AzureBlobStorageAdapter;
use Drupal\flysystem_azure\Flysystem\Azure as AzureBase;

/**
 * Drupal plugin for the "Azure" Flysystem adapter.
 *
 * Contains helfi specific fixes.
 *
 * @Adapter(id = "helfi_azure")
 */
final class Azure extends AzureBase {

  /**
   * {@inheritdoc}
   */
  public function getAdapter(): AzureBlobStorageAdapter {
    return new AzureBlobStorageAdapter($this->getClient(), $this->configuration['container']);
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectionString(): string {

    if (!empty($this->configuration['token'])) {
      $values = [
        'BlobEndpoint' => vsprintf('%s://%s.blob.%s', [
          $this->configuration['protocol'],
          $this->configuration['name'],
          $this->configuration['endpointSuffix'],
        ]),
        'SharedAccessSignature' => $this->configuration['token'],
      ];
    }
    else {
      $values = [
        'DefaultEndpointsProtocol' => $this->configuration['protocol'],
        'AccountName' => $this->configuration['name'],
        'EndpointSuffix' => $this->configuration['endpointSuffix'],
        'AccountKey' => $this->configuration['key'],
      ];
    }
    $connectionString = '';

    foreach ($values as $key => $value) {
      $connectionString .= sprintf('%s=%s;', $key, $value);
    }

    return $connectionString;
  }

}
