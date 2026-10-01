<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Flysystem\Adapter;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Exceptions\BlobStorageException;
use AzureOss\Storage\Blob\Models\Blob;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter as InnerAdapter;
use GuzzleHttp\Exception\GuzzleException;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * The blob storage adapter.
 *
 * Decorates the azure-oss/storage-blob-flysystem adapter with stat(), which
 * checks a file or directory with a single request and caches the results
 * until the adapter changes anything.
 *
 * @see \Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper::url_stat()
 */
final class AzureBlobStorageAdapter implements FilesystemAdapter, ChecksumProvider {

  /**
   * The maximum number of cached stat() results.
   */
  private const int MAX_STATS = 1000;

  /**
   * The decorated adapter.
   */
  private readonly InnerAdapter $inner;

  /**
   * The path prefixer.
   */
  private readonly PathPrefixer $prefixer;

  /**
   * The results of stat(), keyed by path.
   *
   * Drupal checks the same paths repeatedly within a request, like the image
   * style derivatives of a file once per image style and file field. This is
   * cleared whenever the adapter changes anything, and lives as long as the
   * adapter, so only for the current request.
   *
   * @var array<string, \League\Flysystem\FileAttributes|\League\Flysystem\DirectoryAttributes|null>
   */
  private array $stats = [];

  /**
   * Constructs a new instance.
   *
   * @param \AzureOss\Storage\Blob\BlobContainerClient $client
   *   The container client.
   * @param string $prefix
   *   The path prefix inside the container.
   */
  public function __construct(
    private readonly BlobContainerClient $client,
    string $prefix = '',
  ) {
    $this->prefixer = new PathPrefixer($prefix);
    $this->inner = new InnerAdapter(
      $client,
      $prefix,
      // Blob storage has no per-blob visibility: the access level is set for
      // the whole container. Ignore it, since Drupal calls chmod() on every
      // saved file.
      visibilityHandling: InnerAdapter::ON_VISIBILITY_IGNORE,
      // The blobs are served directly from the container. This also keeps
      // the copies on the server side with SAS token credentials.
      isPublicContainer: TRUE,
    );
  }

  /**
   * Gets the attributes of the given file or directory with one request.
   *
   * Checking whether a path is a directory and then fetching its metadata
   * takes one request each. Listing the blobs that start with the path
   * returns both the blob with the same name and the "directory" prefix.
   *
   * The results are cached until the adapter changes anything.
   *
   * @param string $path
   *   The path.
   *
   * @return \League\Flysystem\FileAttributes|\League\Flysystem\DirectoryAttributes|null
   *   The attributes, or NULL if nothing exists in the given path.
   */
  public function stat(string $path): FileAttributes|DirectoryAttributes|null {
    if (!array_key_exists($path, $this->stats)) {
      if (count($this->stats) >= self::MAX_STATS) {
        $this->clearStats();
      }
      $this->stats[$path] = $this->fetchStat($path);
    }
    return $this->stats[$path];
  }

  /**
   * Clears the cached stat() results.
   *
   * Changing a blob can change the result of any path, like whether its
   * parent "directory" exists.
   */
  private function clearStats(): void {
    $this->stats = [];
  }

  /**
   * Fetches the attributes of the given file or directory.
   *
   * @param string $path
   *   The path.
   *
   * @return \League\Flysystem\FileAttributes|\League\Flysystem\DirectoryAttributes|null
   *   The attributes, or NULL if nothing exists in the given path.
   */
  private function fetchStat(string $path): FileAttributes|DirectoryAttributes|null {
    $name = $this->prefixer->prefixPath($path);

    if ($name === '') {
      return new DirectoryAttributes($path);
    }
    $file = NULL;

    try {
      // The listing contains the blob itself, the "directory" prefix and the
      // siblings whose names start with the same name.
      foreach ($this->client->getBlobsByHierarchy($name) as $item) {
        if ($item instanceof Blob) {
          if ($item->name === $name) {
            $file = new FileAttributes(
              $path,
              $item->properties->contentLength,
              NULL,
              $item->properties->lastModified?->getTimestamp(),
              $item->properties->contentType,
            );
          }
          continue;
        }
        // Directories take precedence like in the Flysystem stream wrapper.
        if ($item->name === $name . '/') {
          return new DirectoryAttributes($path);
        }
      }
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToRetrieveMetadata::create($path, 'stat', $e->getMessage(), $e);
    }
    return $file;
  }

  /**
   * {@inheritdoc}
   */
  public function fileExists(string $path): bool {
    return $this->inner->fileExists($path);
  }

  /**
   * {@inheritdoc}
   */
  public function directoryExists(string $path): bool {
    return $this->inner->directoryExists($path);
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, string $contents, Config $config): void {
    $this->clearStats();
    $this->inner->write($path, $contents, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream(string $path, $contents, Config $config): void {
    $this->clearStats();
    $this->inner->writeStream($path, $contents, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    return $this->inner->read($path);
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    return $this->inner->readStream($path);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): void {
    $this->clearStats();
    $this->inner->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDirectory(string $path): void {
    $this->clearStats();
    $this->inner->deleteDirectory($path);
  }

  /**
   * {@inheritdoc}
   */
  public function createDirectory(string $path, Config $config): void {
    $this->inner->createDirectory($path, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function setVisibility(string $path, string $visibility): void {
    $this->inner->setVisibility($path, $visibility);
  }

  /**
   * {@inheritdoc}
   */
  public function visibility(string $path): FileAttributes {
    return $this->inner->visibility($path);
  }

  /**
   * {@inheritdoc}
   */
  public function mimeType(string $path): FileAttributes {
    return $this->inner->mimeType($path);
  }

  /**
   * {@inheritdoc}
   */
  public function lastModified(string $path): FileAttributes {
    return $this->inner->lastModified($path);
  }

  /**
   * {@inheritdoc}
   */
  public function fileSize(string $path): FileAttributes {
    return $this->inner->fileSize($path);
  }

  /**
   * {@inheritdoc}
   */
  public function listContents(string $path, bool $deep): iterable {
    return $this->inner->listContents($path, $deep);
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $source, string $destination, Config $config): void {
    $this->clearStats();
    $this->inner->move($source, $destination, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $source, string $destination, Config $config): void {
    $this->clearStats();
    $this->inner->copy($source, $destination, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function checksum(string $path, Config $config): string {
    return $this->inner->checksum($path, $config);
  }

}
