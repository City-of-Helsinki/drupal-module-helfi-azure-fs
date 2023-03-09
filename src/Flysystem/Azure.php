<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs\Flysystem;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\flysystem_azure\Flysystem\Adapter\AzureBlobStorageAdapter;
use Drupal\flysystem_azure\Flysystem\Azure as AzureBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drupal plugin for the "Azure" Flysystem adapter.
 *
 * Contains helfi specific fixes.
 *
 * @Adapter(id = "helfi_azure")
 */
final class Azure extends AzureBase {

  /**
   * The file url generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  private FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * {@inheritdoc}
   */
  public function getAdapter(): AzureBlobStorageAdapter {
    return new AzureBlobStorageAdapter($this->getClient(), $this->configuration['container']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl($uri): string {
    // @todo explain why we do this.
    if (str_contains($uri, 'styles/') && !file_exists($uri)) {
      $uri = str_replace('azure://', 'public://', $uri);

      return $this->fileUrlGenerator->generateString($uri);
    }
    $target = $this->getTarget($uri);

    return sprintf('%s/%s', $this->calculateUrlPrefix(), UrlHelper::encodePath($target));
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
