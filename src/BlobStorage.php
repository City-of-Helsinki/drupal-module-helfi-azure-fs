<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\Models\Blob;
use AzureOss\Storage\Blob\Models\BlobHttpHeaders;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use Drupal\Core\Site\Settings;
use Psr\Http\Message\StreamInterface;

/**
 * Stores the files in an Azure Blob Storage container.
 *
 * Configure it in settings.php:
 *
 * @code
 * $settings['helfi_azure_fs'] = [
 *   'name' => '[account name]',
 *   'container' => '[container]',
 *   // Either a SAS token, an account key or a connection string.
 *   'token' => '[SAS token]',
 *   // The URL the files are served from.
 *   'public_url_base' => 'https://[account name].blob.core.windows.net/[container]',
 * ];
 * @endcode
 *
 * Blob storage has no directories: they exist implicitly as the prefixes of
 * the blob names.
 */
final class BlobStorage {

  /**
   * The settings key.
   */
  public const string SETTINGS_KEY = 'helfi_azure_fs';

  /**
   * The stream wrapper scheme.
   */
  public const string SCHEME = 'azure';

  /**
   * The maximum number of cached stat() results.
   */
  private const int MAX_STATS = 1000;

  /**
   * The container client.
   */
  private ?BlobContainerClient $client = NULL;

  /**
   * The results of stat(), keyed by path.
   *
   * Drupal checks the same paths repeatedly within a request, like the image
   * style derivatives of a file once per image style. All writes go through
   * this service, which clears the cache.
   *
   * @var array<string, array{directory: bool, size: int, mtime: int}|null>
   */
  private array $stats = [];

  /**
   * The directories created during the request, keyed by path.
   *
   * Blob storage has no directories, so the empty ones don't exist. Report
   * the created ones as existing, so the callers can check whether the
   * directory exists after creating it.
   *
   * @var array<string, true>
   */
  private array $directories = [];

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   */
  public function __construct(
    private readonly Settings $settings,
  ) {
  }

  /**
   * Gets the configuration from the given settings.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   *
   * @return array<string, string>|null
   *   The configuration, or NULL if the blob storage is not configured.
   */
  public static function getConfigurationFromSettings(Settings $settings): ?array {
    $configuration = $settings->get(self::SETTINGS_KEY);

    return empty($configuration) ? NULL : $configuration;
  }

  /**
   * Gets the configuration.
   *
   * @return array<string, string>
   *   The configuration.
   */
  private function getConfiguration(): array {
    $configuration = self::getConfigurationFromSettings($this->settings);

    foreach (['container', 'public_url_base'] as $key) {
      if (empty($configuration[$key])) {
        throw new \LogicException(sprintf('The "%s" is missing from $settings[\'%s\'].', $key, self::SETTINGS_KEY));
      }
    }
    return $configuration;
  }

  /**
   * Checks whether the blob storage is configured.
   *
   * @return bool
   *   TRUE if the blob storage is configured.
   */
  public function isConfigured(): bool {
    return self::getConfigurationFromSettings($this->settings) !== NULL;
  }

  /**
   * Gets the container client.
   *
   * The client is shared, so the requests reuse the same connection.
   *
   * @return \AzureOss\Storage\Blob\BlobContainerClient
   *   The container client.
   */
  private function getClient(): BlobContainerClient {
    if ($this->client === NULL) {
      $configuration = $this->getConfiguration();
      $this->client = BlobServiceClient::fromConnectionString($this->getConnectionString($configuration))
        ->getContainerClient($configuration['container']);
    }
    return $this->client;
  }

  /**
   * Gets the connection string.
   *
   * @param array<string, string> $configuration
   *   The configuration.
   *
   * @return string
   *   The connection string.
   */
  private function getConnectionString(array $configuration): string {
    if (!empty($configuration['connectionString'])) {
      return $configuration['connectionString'];
    }
    if (!empty($configuration['token'])) {
      return sprintf('BlobEndpoint=https://%s.blob.core.windows.net;SharedAccessSignature=%s;', $configuration['name'], $configuration['token']);
    }
    return sprintf(
      'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net;',
      $configuration['name'],
      $configuration['key'] ?? '',
    );
  }

  /**
   * Gets the public URL of the given file.
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The URL.
   */
  public function getPublicUrl(string $path): string {
    $path = implode('/', array_map('rawurlencode', explode('/', self::normalize($path))));

    return rtrim($this->getConfiguration()['public_url_base'], '/') . '/' . $path;
  }

  /**
   * Normalizes the given path into a blob name.
   *
   * Removes duplicate, leading and trailing slashes, since they're part of
   * the blob name: "/a//b.txt/" becomes "a/b.txt".
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The blob name, without leading, trailing or duplicate slashes.
   */
  public static function normalize(string $path): string {
    return trim((string) preg_replace('#/+#', '/', $path), '/');
  }

  /**
   * Gets the attributes of the given file or directory with one request.
   *
   * Listing the blobs that start with the path returns both the blob with the
   * same name and the "directory" prefix.
   *
   * @param string $path
   *   The path.
   *
   * @return array{directory: bool, size: int, mtime: int}|null
   *   The attributes, or NULL if nothing exists in the given path.
   */
  public function stat(string $path): ?array {
    $name = self::normalize($path);

    if (!array_key_exists($name, $this->stats)) {
      if (count($this->stats) >= self::MAX_STATS) {
        $this->clearStats();
      }
      $this->stats[$name] = $this->fetchStat($name);
    }
    return $this->stats[$name];
  }

  /**
   * Fetches the attributes of the given file or directory.
   *
   * @param string $name
   *   The blob name.
   *
   * @return array{directory: bool, size: int, mtime: int}|null
   *   The attributes, or NULL if nothing exists in the given path.
   */
  private function fetchStat(string $name): ?array {
    if ($name === '' || isset($this->directories[$name])) {
      return ['directory' => TRUE, 'size' => 0, 'mtime' => 0];
    }
    $file = NULL;

    // The listing contains the blob itself, the "directory" prefix and the
    // siblings whose names start with the same name.
    foreach ($this->getClient()->getBlobsByHierarchy($name) as $item) {
      if ($item instanceof Blob) {
        if ($item->name === $name) {
          $file = [
            'directory' => FALSE,
            'size' => $item->properties->contentLength,
            'mtime' => $item->properties->lastModified?->getTimestamp() ?? 0,
          ];
        }
        continue;
      }
      if ($item->name === $name . '/') {
        return ['directory' => TRUE, 'size' => 0, 'mtime' => 0];
      }
    }
    return $file;
  }

  /**
   * Creates the given directory and its parents for the current request.
   *
   * @param string $path
   *   The directory.
   */
  public function createDirectory(string $path): void {
    $parts = explode('/', self::normalize($path));

    while ($parts) {
      $name = implode('/', $parts);
      $this->directories[$name] = TRUE;
      unset($this->stats[$name]);
      array_pop($parts);
    }
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
   * Reads the given file.
   *
   * @param string $path
   *   The path.
   *
   * @return \Psr\Http\Message\StreamInterface
   *   The contents.
   */
  public function read(string $path): StreamInterface {
    return $this->getClient()
      ->getBlobClient(self::normalize($path))
      ->downloadStreaming()
      ->content;
  }

  /**
   * Writes the given file.
   *
   * @param string $path
   *   The path.
   * @param resource|string $contents
   *   The contents.
   * @param string $mimeType
   *   The MIME type.
   */
  public function write(string $path, mixed $contents, string $mimeType): void {
    $this->clearStats();
    $this->getClient()
      ->getBlobClient(self::normalize($path))
      ->upload($contents, new UploadBlobOptions(httpHeaders: new BlobHttpHeaders(contentType: $mimeType)));
  }

  /**
   * Deletes the given file.
   *
   * @param string $path
   *   The path.
   */
  public function delete(string $path): void {
    $this->clearStats();
    $this->getClient()
      ->getBlobClient(self::normalize($path))
      ->deleteIfExists();
  }

  /**
   * Copies the given file on the server side.
   *
   * @param string $source
   *   The source path.
   * @param string $destination
   *   The destination path.
   */
  public function copy(string $source, string $destination): void {
    $this->clearStats();
    $client = $this->getClient();
    $client->getBlobClient(self::normalize($destination))
      ->syncCopyFromUri($client->getBlobClient(self::normalize($source))->uri);
  }

  /**
   * Lists the names of the files and directories inside the given directory.
   *
   * @param string $path
   *   The directory.
   *
   * @return string[]
   *   The base names.
   */
  public function listDirectory(string $path): array {
    $prefix = self::normalize($path);
    $prefix = $prefix === '' ? '' : $prefix . '/';
    $names = [];

    foreach ($this->getClient()->getBlobsByHierarchy($prefix) as $item) {
      $names[] = rtrim(substr($item->name, strlen($prefix)), '/');
    }
    return $names;
  }

}
