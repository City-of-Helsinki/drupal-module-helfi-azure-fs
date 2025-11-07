<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Flysystem;

use AzureOss\Storage\Blob\BlobServiceClient;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function PHPUnit\Framework\returnValue;

/**
 * Drupal plugin for the "Azure" Flysystem adapter.
 *
 * Contains helfi specific fixes.
 *
 * @Adapter(id = "helfi_azure")
 */
final class Azure implements FlysystemPluginInterface, ContainerFactoryPluginInterface {

  use FlysystemUrlTrait {
    getExternalUrl as getDownloadUrl;
  }

  /**
   * List of urls already requested, indexed by uri.
   *
   * @var string[]
   */
  private array $externalUrls = [];

  /**
   * Constructs an Azure object.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly array $configuration,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $container->get('logger.factory')->get('flysystem_azure'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAdapter(): AzureBlobStorageAdapter {
    $client = BlobServiceClient::fromConnectionString($this->getConnectionString())
      ->getContainerClient($this->configuration['container']);

    return new AzureBlobStorageAdapter($client);
  }

  /**
   * Gets the connection string.
   *
   * @return string
   *   The connection string.
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
    if (str_contains($uri, '/styles/') && !file_exists($uri)) {
      // Return a 'local' image style URL until the image is generated and
      // copied to Azure blob storage. Each derivative is generated when the
      // image style URL is called for the first time, allowing the generation
      // to be decoupled from main request.
      return $this->externalUrls[$uri] = $this->getDownloadUrl($uri);
    }
    $target = $this->getTarget($uri);

    return $this->externalUrls[$uri] = sprintf('%s/%s',
      $this->calculateUrlPrefix(), UrlHelper::encodePath($target));
  }

  /**
   * {@inheritdoc}
   */
  public function ensure($force = FALSE): array {
    try {
      $this->getAdapter()->listContents();
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }

    return [];
  }

  /**
   * Calculates the URL prefix.
   *
   * @return string
   *   The URL prefix in the form
   *   protocol://[name].blob.[endpointSuffix]/[container].
   */
  protected function calculateUrlPrefix(): string {
    return $this->configuration['protocol'] . '://' . $this->configuration['name'] . '.blob.' .
      $this->configuration['endpointSuffix'] . '/' . $this->configuration['container'];
  }

}
