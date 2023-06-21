<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs\Flysystem;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\flysystem_azure\Flysystem\Adapter\AzureBlobStorageAdapter;
use Drupal\flysystem_azure\Flysystem\Azure as AzureBase;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Internal\BlobResources;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedAccessSignatureAuthScheme;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedKeyAuthScheme;
use MicrosoftAzure\Storage\Common\Internal\Middlewares\CommonRequestMiddleware;
use MicrosoftAzure\Storage\Common\Internal\Resources;
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
   * List of urls already requested, indexed by uri.
   *
   * @var string[]
   */
  private array $externalUrls = [];


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
  public function getAdapter(): AzureBlobStorageAdapter {
    return new AzureBlobStorageAdapter($this->getClient(), $this->configuration['container']);
  }

  /**
   * Gets the blob storage URI.
   *
   * @param string|null $suffix
   *   The URI suffix.
   *
   * @return string
   *   The blob storage uri.
   */
  private function getBlobUri(?string $suffix = NULL) : string {
    return vsprintf('%s://%s%s.blob.%s', [
      $this->configuration['protocol'],
      $this->configuration['name'],
      $suffix ?: '',
      $this->configuration['endpointSuffix'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(): BlobRestProxy {
    if (!isset($this->client)) {
      // Construct the client manually to avoid calling substr with null
      // $haystack parameter.
      // @see https://github.com/Azure/azure-storage-php/issues/347
      $wrapper = new BlobRestProxy(
        $this->getBlobUri(),
        $this->getBlobUri(Resources::SECONDARY_STRING),
        ''
      );

      if (!empty($this->configuration['token'])) {
        $authScheme = new SharedAccessSignatureAuthScheme(
          $this->configuration['token']
        );
      }
      else {
        $authScheme = new SharedKeyAuthScheme(
          $this->configuration['name'],
          $this->configuration['key'],
        );
      }
      $commonRequestMiddleware = new CommonRequestMiddleware(
        $authScheme,
        BlobResources::STORAGE_API_LATEST_VERSION,
        BlobResources::BLOB_SDK_VERSION
      );
      $wrapper->pushMiddleware($commonRequestMiddleware);
      $this->client = $wrapper;
    }
    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl($uri): string {
    // This could get called multiple times in a request, currently 2 times,
    // and the file_exists below takes time, so we use a 'static' cache.
    if (isset($this->externalUrls[$uri])) {
      return $this->externalUrls[$uri];
    }
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
      $localUri = str_replace('azure://', 'public://', $uri);

      return $this->externalUrls[$uri] = UrlHelper::encodePath(
        $this->fileUrlGenerator->generateString($localUri));
    }
    $target = $this->getTarget($uri);

    return $this->externalUrls[$uri] = sprintf('%s/%s',
      $this->calculateUrlPrefix(), UrlHelper::encodePath($target));
  }

}
