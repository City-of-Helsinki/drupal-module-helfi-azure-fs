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
    // The original ::getExternalUrl method generates image styles on the fly,
    // blocking the request until all derivatives on that page are generated.
    // We use 'responsive_image' module, so each image can generate up to
    // four derivatives, each taking several seconds.
    // @see https://helsinkisolutionoffice.atlassian.net/browse/UHF-8204
    if (str_contains($uri, 'styles/') && !file_exists($uri)) {
      // Return a 'local' image style URL until the image is generated and
      // copied to Azure blob storage. Each derivative is generated when the
      // image style URL is called for the first time, allowing the generation
      // to be decoupled from main request.
      $uri = str_replace('azure://', 'public://', $uri);

      // @todo invalidate cache tags using this file.
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
