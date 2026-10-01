<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Flysystem\Adapter;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Exceptions\BlobStorageException;
use AzureOss\Storage\Blob\Models\Blob;
use AzureOss\Storage\Blob\Models\BlobProperties;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use GuzzleHttp\Exception\GuzzleException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

/**
 * The blob storage adapter.
 *
 * A league/flysystem 3.x adapter for Azure Blob Storage, built on
 * azure-oss/storage. Originally ported from
 * league/flysystem-azure-blob-storage:1.0.0.
 */
final class AzureBlobStorageAdapter implements FilesystemAdapter {

  /**
   * The path prefixer.
   */
  private readonly PathPrefixer $prefixer;

  /**
   * The mime type detector.
   */
  private readonly MimeTypeDetector $mimeTypeDetector;

  /**
   * Constructs a new instance.
   *
   * @param \AzureOss\Storage\Blob\BlobContainerClient $client
   *   The container client.
   * @param string $prefix
   *   The path prefix inside the container.
   * @param \League\MimeTypeDetection\MimeTypeDetector|null $mimeTypeDetector
   *   The mime type detector.
   */
  public function __construct(
    private readonly BlobContainerClient $client,
    string $prefix = '',
    ?MimeTypeDetector $mimeTypeDetector = NULL,
  ) {
    $this->prefixer = new PathPrefixer($prefix);
    $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
  }

  /**
   * {@inheritdoc}
   */
  public function fileExists(string $path): bool {
    try {
      return $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->exists();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToCheckFileExistence::forLocation($path, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function directoryExists(string $path): bool {
    try {
      // Stops after the first matching blob.
      return $this->client->getBlobs($this->prefixer->prefixDirectoryPath($path))
        ->valid();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToCheckDirectoryExistence::forLocation($path, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(string $path, string $contents, Config $config): void {
    $this->upload($path, $contents, $config);
  }

  /**
   * {@inheritdoc}
   */
  public function writeStream(string $path, $contents, Config $config): void {
    $this->upload($path, $contents, $config);
  }

  /**
   * Uploads the given contents.
   *
   * @param string $path
   *   The path.
   * @param string|resource $contents
   *   The contents.
   * @param \League\Flysystem\Config $config
   *   The config.
   */
  private function upload(string $path, mixed $contents, Config $config): void {
    $mimeType = $config->get('mimetype') ?? (is_string($contents)
      ? $this->mimeTypeDetector->detectMimeType($path, $contents)
      : $this->mimeTypeDetector->detectMimeTypeFromPath($path));

    try {
      $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->upload($contents, new UploadBlobOptions(contentType: $mimeType));
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $path): string {
    try {
      return $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->downloadStreaming()
        ->content
        ->getContents();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function readStream(string $path) {
    try {
      $stream = $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->downloadStreaming()
        ->content
        ->detach();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }

    if (!is_resource($stream)) {
      throw UnableToReadFile::fromLocation($path, 'Unable to open the download stream.');
    }
    return $stream;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $path): void {
    try {
      $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->deleteIfExists();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDirectory(string $path): void {
    try {
      foreach ($this->client->getBlobs($this->prefixer->prefixDirectoryPath($path)) as $blob) {
        $this->client->getBlobClient($blob->name)->deleteIfExists();
      }
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createDirectory(string $path, Config $config): void {
    // Blob storage has no real directories: they exist implicitly as the
    // prefixes of the blob names.
  }

  /**
   * {@inheritdoc}
   */
  public function setVisibility(string $path, string $visibility): void {
    // Blob storage has no per-blob visibility: the access level is set for
    // the whole container. Ignore it, since Drupal calls chmod() on every
    // saved file.
  }

  /**
   * {@inheritdoc}
   */
  public function visibility(string $path): FileAttributes {
    throw UnableToRetrieveMetadata::visibility($path, 'Azure Blob Storage does not support visibility.');
  }

  /**
   * {@inheritdoc}
   */
  public function mimeType(string $path): FileAttributes {
    $attributes = $this->fetchFileAttributes($path, FileAttributes::ATTRIBUTE_MIME_TYPE);

    if ($attributes->mimeType() === NULL) {
      throw UnableToRetrieveMetadata::mimeType($path);
    }
    return $attributes;
  }

  /**
   * {@inheritdoc}
   */
  public function lastModified(string $path): FileAttributes {
    return $this->fetchFileAttributes($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
  }

  /**
   * {@inheritdoc}
   */
  public function fileSize(string $path): FileAttributes {
    return $this->fetchFileAttributes($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
  }

  /**
   * Fetches the file attributes of the given blob.
   *
   * @param string $path
   *   The path.
   * @param string $type
   *   The requested metadata type, used in the exception message.
   *
   * @return \League\Flysystem\FileAttributes
   *   The file attributes.
   */
  private function fetchFileAttributes(string $path, string $type): FileAttributes {
    try {
      $properties = $this->client->getBlobClient($this->prefixer->prefixPath($path))
        ->getProperties();
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
    }
    return $this->normalizeBlobProperties($path, $properties);
  }

  /**
   * {@inheritdoc}
   */
  public function listContents(string $path, bool $deep): iterable {
    try {
      $directories = [$this->prefixer->prefixDirectoryPath($path)];

      while ($directories) {
        $currentPrefix = array_shift($directories);

        foreach ($this->client->getBlobsByHierarchy($currentPrefix) as $item) {
          if ($item instanceof Blob) {
            yield $this->normalizeBlobProperties($this->prefixer->stripPrefix($item->name), $item->properties);
            continue;
          }
          yield new DirectoryAttributes(rtrim($this->prefixer->stripPrefix($item->name), '/'));

          if ($deep) {
            $directories[] = $item->name;
          }
        }
      }
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToListContents::atLocation($path, $deep, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function move(string $source, string $destination, Config $config): void {
    try {
      $this->copy($source, $destination, $config);
      $this->delete($source);
    }
    catch (UnableToCopyFile | UnableToDeleteFile $e) {
      throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function copy(string $source, string $destination, Config $config): void {
    $sourceBlobClient = $this->client->getBlobClient($this->prefixer->prefixPath($source));
    $targetBlobClient = $this->client->getBlobClient($this->prefixer->prefixPath($destination));

    try {
      $targetBlobClient->syncCopyFromUri($sourceBlobClient->uri);
    }
    catch (BlobStorageException | GuzzleException $e) {
      throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
    }
  }

  /**
   * Normalizes the given blob properties.
   *
   * @param string $path
   *   The path, without the prefix.
   * @param \AzureOss\Storage\Blob\Models\BlobProperties $properties
   *   The properties.
   *
   * @return \League\Flysystem\FileAttributes
   *   The file attributes.
   */
  private function normalizeBlobProperties(string $path, BlobProperties $properties): FileAttributes {
    return new FileAttributes(
      $path,
      $properties->contentLength,
      NULL,
      $properties->lastModified->getTimestamp(),
      $properties->contentType,
    );
  }

}
