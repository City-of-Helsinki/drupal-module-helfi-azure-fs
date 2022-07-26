<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs\Flysystem;

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
  public function getConnectionString(): string {
    $values = [
      'DefaultEndpointsProtocol' => $this->configuration['protocol'],
      'AccountName' => $this->configuration['name'],
      'EndpointSuffix' => $this->configuration['endpointSuffix'],
    ];

    if (!empty($this->configuration['token'])) {
      $values['SharedAccessSignature'] = $this->configuration['token'];
    }
    else {
      $values['AccountKey'] = $this->configuration['key'];
    }

    return http_build_query($values, arg_separator: ';');
  }

}
