<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Exceptions\BlobNotFoundException;
use AzureOss\Storage\Blob\Models\BlobProperties;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;

use function compact;
use function stream_get_contents;

/**
 * The blob storage adapter.
 *
 *  This is originally from league/flysystem-azure-blob-storage:1.0.0,
 *  modified to work with azure-oss/storage.
 *
 * The azure-oss/azure-storage-php-adapter-flysystem requires 3.x version
 * of flysystem and Drupal only supports 2.x at the moment.
 */
class AzureBlobStorageAdapter extends AbstractAdapter {

  use NotSupportingVisibilityTrait;

  /**
   * Constructs a new instance.
   *
   * @param \AzureOss\Storage\Blob\BlobContainerClient $client
   *   The container client.
   * @param string|null $prefix
   *   The prefix.
   */
  public function __construct(
    protected BlobContainerClient $client,
    ?string $prefix = NULL,
  ) {
    $this->setPathPrefix($prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function write($path, $contents, Config $config): array {
    return $this->upload($path, $contents) + compact('contents');
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream($path, $resource, Config $config): array {
    return $this->upload($path, $resource);
  }

  /**
   * Upload the given file.
   *
   * @param string $path
   *   The path.
   * @param string|resource $contents
   *   The contents.
   *
   * @return array
   *   The metadata.
   */
  protected function upload(string $path, mixed $contents): array {
    $destination = $this->applyPathPrefix($path);
    $options = new UploadBlobOptions(
      contentType: Util::guessMimeType($path, $contents),
    );

    $this->client->getBlobClient($destination)
      ->upload($contents, $options);

    return [
      'path' => $path,
      'dirname' => Util::dirname($path),
      'type' => 'file',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function update($path, $contents, Config $config): array {
    return $this->upload($path, $contents) + compact('contents');
  }

  /**
   * {@inheritdoc}
   */
  public function updateStream($path, $resource, Config $config): array {
    return $this->upload($path, $resource);
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

    $sourceBlobClient = $this->client->getBlobClient($source);
    $targetBlobClient = $this->client->getBlobClient($destination);

    $targetBlobClient->syncCopyFromUri($sourceBlobClient->uri);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path): bool {
    $this->client->getBlobClient($this->applyPathPrefix($path))
      ->deleteIfExists();

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDir($dirname): bool {

    foreach ($this->listContents($dirname) as $file) {
      if ($file['type'] === 'file') {
        $this->client->getBlobClient($this->applyPathPrefix($file['path']))
          ->delete();
      }
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

    $response = $this->client->getBlobClient($location)
      ->downloadStreaming();

    return $this->normalizeBlobProperties(
        $path,
        $response->properties
      ) + ['stream' => $response->content->detach()];
  }

  /**
   * {@inheritdoc}
   */
  public function listContents($directory = '', $recursive = FALSE): \Generator {
    $prefix = $this->applyPathPrefix($directory);
    $directories = [$prefix];

    while (!empty($directories)) {
      $currentPrefix = array_shift($directories);

      foreach ($this->client->getBlobsByHierarchy($currentPrefix) as $item) {
        yield $this->normalizeBlobProperties($this->applyPathPrefix($item->name), $item->properties);

        if ($recursive) {
          $directories[] = $item->name;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata($path): array|bool {
    $path = $this->applyPathPrefix($path);

    try {
      $properties = $this->client->getBlobClient($path)->getProperties();

      return $this->normalizeBlobProperties(
        $path,
        $properties,
      );
    }
    catch (BlobNotFoundException) {
    }

    if (in_array($path, ['css', 'js'])) {
      return [
        'type' => 'dir',
        'path' => $path,
      ];
    }

    return FALSE;
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
   * Normalizes the given blob properties.
   *
   * @param string $path
   *   The path.
   * @param \AzureOss\Storage\Blob\Models\BlobProperties $properties
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
      'timestamp' => (int) $properties->lastModified->format('U'),
      'dirname' => Util::dirname($path),
      'mimetype' => $properties->contentType,
      'size' => $properties->contentLength,
      'type' => 'file',
    ];
  }

}
