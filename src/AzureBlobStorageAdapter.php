<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use GuzzleHttp\Psr7\Utils as GuzzleUtil;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\BlobPrefix;
use MicrosoftAzure\Storage\Blob\Models\BlobProperties;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Common\Models\ContinuationToken;

use function array_merge;
use function compact;
use function stream_get_contents;
use function strpos;

/**
 * The blob storage adapter.
 *
 *  AzureBlobStorageAdapter.php from league/flysystem-azure-blob-storage:1.0.0.
 *  Modified to make this play nicely with Drupal 10 dependencies.
 */
class AzureBlobStorageAdapter extends AbstractAdapter {

  use NotSupportingVisibilityTrait;

  /**
   * The meta options.
   *
   * @var string[]
   */
  protected static array $metaOptions = [
    'CacheControl',
    'ContentType',
    'Metadata',
    'ContentLanguage',
    'ContentEncoding',
  ];

  /**
   * The maximum number of results in content listing.
   *
   * @var int
   */
  private $maxResultsForContentsListing = 5000;

  /**
   * Constructs a new instance.
   *
   * @param \MicrosoftAzure\Storage\Blob\BlobRestProxy $client
   *   The client.
   * @param string $container
   *   The container.
   * @param string|null $prefix
   *   The prefix.
   */
  public function __construct(
    protected BlobRestProxy $client,
    protected string $container,
    ?string $prefix = NULL,
  ) {
    $this->setPathPrefix($prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function write($path, $contents, Config $config): array {
    return $this->upload($path, $contents, $config) + compact('contents');
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream($path, $resource, Config $config): array {
    return $this->upload($path, $resource, $config);
  }

  /**
   * Upload the given file.
   *
   * @param string $path
   *   The path.
   * @param string|resource $contents
   *   The contents.
   * @param \League\Flysystem\Config $config
   *   The config object.
   *
   * @return array
   *   The metadata.
   */
  protected function upload(string $path, mixed $contents, Config $config): array {
    $destination = $this->applyPathPrefix($path);

    $options = $this->getOptionsFromConfig($config);

    if (empty($options->getContentType())) {
      $options->setContentType(Util::guessMimeType($path, $contents));
    }

    // We manually create the stream to prevent it from closing the resource
    // in its destructor.
    $stream = GuzzleUtil::streamFor($contents);
    $response = $this->client->createBlockBlob(
      $this->container,
      $destination,
      $contents,
      $options
    );

    $stream->detach();

    return [
      'path' => $path,
      'timestamp' => (int) $response->getLastModified()->getTimestamp(),
      'dirname' => Util::dirname($path),
      'type' => 'file',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function update($path, $contents, Config $config): array {
    return $this->upload($path, $contents, $config) + compact('contents');
  }

  /**
   * {@inheritdoc}
   */
  public function updateStream($path, $resource, Config $config): array {
    return $this->upload($path, $resource, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path, $newpath): bool {
    return $this->copy($path, $newpath) && $this->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function copy($path, $newpath): bool {
    $source = $this->applyPathPrefix($path);
    $destination = $this->applyPathPrefix($newpath);
    $this->client->copyBlob($this->container, $destination, $this->container, $source);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path): bool {
    try {
      $this->client->deleteBlob($this->container, $this->applyPathPrefix($path));
    }
    catch (ServiceException $exception) {
      if ($exception->getCode() !== 404) {
        throw $exception;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDir($dirname): bool {
    $prefix = $this->applyPathPrefix($dirname);
    $options = new ListBlobsOptions();
    $options->setPrefix($prefix . '/');
    $listResults = $this->client->listBlobs($this->container, $options);
    foreach ($listResults->getBlobs() as $blob) {
      $this->client->deleteBlob($this->container, $blob->getName());
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function createDir($dirname, Config $config): array {
    return ['path' => $dirname, 'type' => 'dir'];
  }

  /**
   * {@inheritdoc}
   */
  public function has($path): bool {
    return (bool) $this->getMetadata($path);
  }

  /**
   * {@inheritdoc}
   */
  public function read($path): array {
    $response = $this->readStream($path);

    if (!isset($response['stream']) || !is_resource($response['stream'])) {
      return $response;
    }

    $response['contents'] = stream_get_contents($response['stream']);
    unset($response['stream']);

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function readStream($path): array|bool {
    $location = $this->applyPathPrefix($path);

    try {
      $response = $this->client->getBlob(
        $this->container,
        $location
      );

      return $this->normalizeBlobProperties(
          $path,
          $response->getProperties()
        ) + ['stream' => $response->getContentStream()];
    }
    catch (ServiceException $exception) {
      if ($exception->getCode() !== 404) {
        throw $exception;
      }

      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function listContents($directory = '', $recursive = FALSE): array {
    $result = [];
    $location = $this->applyPathPrefix($directory);

    if (strlen($location) > 0) {
      $location = rtrim($location, '/') . '/';
    }

    $options = new ListBlobsOptions();
    $options->setPrefix($location);
    $options->setMaxResults($this->maxResultsForContentsListing);

    if (!$recursive) {
      $options->setDelimiter('/');
    }

    list_contents:
    $response = $this->client->listBlobs($this->container, $options);
    $continuationToken = $response->getContinuationToken();
    foreach ($response->getBlobs() as $blob) {
      $name = $blob->getName();

      if ($location === '' || strpos($name, $location) === 0) {
        $result[] = $this->normalizeBlobProperties($name, $blob->getProperties());
      }
    }

    if (!$recursive) {
      $result = array_merge($result, array_map([$this, 'normalizeBlobPrefix'], $response->getBlobPrefixes()));
    }

    if ($continuationToken instanceof ContinuationToken) {
      $options->setContinuationToken($continuationToken);
      goto list_contents;
    }

    return Util::emulateDirectories($result);
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata($path): array|bool {
    $path = $this->applyPathPrefix($path);

    try {
      return $this->normalizeBlobProperties(
        $path,
        $this->client->getBlobProperties($this->container, $path)->getProperties()
      );
    }
    catch (ServiceException $exception) {
      if ($exception->getCode() !== 404) {
        throw $exception;
      }

      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSize($path): array|bool {
    return $this->getMetadata($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getMimetype($path): array|bool {
    return $this->getMetadata($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestamp($path): array|bool {
    return $this->getMetadata($path);
  }

  /**
   * Gets the blob options.
   *
   * @param \League\Flysystem\Config $config
   *   The config.
   *
   * @return mixed
   *   The options.
   */
  protected function getOptionsFromConfig(Config $config) {
    $options = $config->get('blobOptions', new CreateBlockBlobOptions());
    foreach (static::$metaOptions as $option) {
      if (!$config->has($option)) {
        continue;
      }
      call_user_func([$options, "set$option"], $config->get($option));
    }
    if ($mimetype = $config->get('mimetype')) {
      $options->setContentType($mimetype);
    }

    return $options;
  }

  /**
   * Normalizes the given blob properties.
   *
   * @param string $path
   *   The path.
   * @param \MicrosoftAzure\Storage\Blob\Models\BlobProperties $properties
   *   The properties.
   *
   * @return array
   *   The normalized properties.
   */
  protected function normalizeBlobProperties(string $path, BlobProperties $properties): array {
    $path = $this->removePathPrefix($path);

    if (str_ends_with($path, '/')) {
      return ['type' => 'dir', 'path' => rtrim($path, '/')];
    }

    return [
      'path' => $path,
      'timestamp' => (int) $properties->getLastModified()->format('U'),
      'dirname' => Util::dirname($path),
      'mimetype' => $properties->getContentType(),
      'size' => $properties->getContentLength(),
      'type' => 'file',
    ];
  }

  /**
   * Sets the maximum number of results for content listing.
   *
   * @param int $numberOfResults
   *   The number of results.
   */
  public function setMaxResultsForContentsListing(int $numberOfResults): void {
    $this->maxResultsForContentsListing = $numberOfResults;
  }

  /**
   * Normalizes the given blob prefix.
   *
   * @param \MicrosoftAzure\Storage\Blob\Models\BlobPrefix $blobPrefix
   *   The prefix.
   *
   * @return array
   *   The normalized blob prefix.
   */
  protected function normalizeBlobPrefix(BlobPrefix $blobPrefix): array {
    return [
      'type' => 'dir',
      'path' => $this->removePathPrefix(rtrim($blobPrefix->getName(), '/')),
    ];
  }

}
