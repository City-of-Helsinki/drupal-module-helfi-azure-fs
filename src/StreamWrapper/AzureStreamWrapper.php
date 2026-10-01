<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\StreamWrapper;

use Drupal\flysystem\Exception\AdapterConfigurationException;
use Drupal\flysystem\StreamWrapper\FlysystemStreamWrapper;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;

/**
 * The stream wrapper for Azure Blob Storage schemes.
 *
 * Flysystem routes image style derivatives of remote adapters through Drupal,
 * which then streams the derivative from the blob storage on every request.
 *
 * This serves existing derivatives directly from the blob storage instead, and
 * only routes the missing ones through Drupal, so they are generated on the
 * first request without blocking the page that renders them.
 *
 * @see https://helsinkisolutionoffice.atlassian.net/browse/UHF-8204
 * @see \Drupal\helfi_azure_fs\HelfiAzureFsServiceProvider
 */
final class AzureStreamWrapper extends FlysystemStreamWrapper {

  /**
   * The external URLs of image style derivatives, keyed by URI.
   *
   * The derivative URLs can be built multiple times per request and checking
   * whether the derivative exists requires a request to the blob storage.
   *
   * @var array<string, string>
   */
  private array $derivativeUrls = [];

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl(): string {
    $target = $this->getTarget();

    if (!str_starts_with($target, 'styles/')) {
      return parent::getExternalUrl();
    }

    if (!isset($this->derivativeUrls[$this->uri])) {
      $this->derivativeUrls[$this->uri] = $this->getBlobUrl($target) ?? parent::getExternalUrl();
    }
    return $this->derivativeUrls[$this->uri];
  }

  /**
   * Gets the blob storage URL of the given file, if it exists.
   *
   * @param string $target
   *   The path of the file.
   *
   * @return string|null
   *   The URL, or NULL if the file doesn't exist or can't be served
   *   directly from the blob storage.
   */
  private function getBlobUrl(string $target): ?string {
    $scheme = $this->getScheme();

    try {
      $definition = $this->getFactory()->getDefinition($scheme);

      if ($definition->publicUrlBase === NULL || $definition->visibility === 'private') {
        return NULL;
      }

      if (!$this->getFactory()->getFilesystem($scheme)->fileExists($target)) {
        return NULL;
      }
    }
    catch (AdapterConfigurationException | FilesystemException) {
      return NULL;
    }
    return rtrim($definition->publicUrlBase, '/') . '/' . $this->encodeExternalPath($target);
  }

  /**
   * {@inheritdoc}
   *
   * Flysystem checks whether the path is a directory and then fetches the
   * file metadata, taking up to four requests per stat. This is called for
   * every file_exists(), is_dir(), filesize() etc., so do it with one.
   *
   * @return array<int|string, int>|false
   *   The stat array, or FALSE if the path doesn't exist.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function url_stat($path, $flags) {
    $this->uri = $path;
    $scheme = $this->getScheme();

    try {
      $definition = $this->getFactory()->getDefinition($scheme);
      $adapter = $this->getFactory()->getDriver($scheme)->buildAdapter($definition->config);

      if (!$adapter instanceof AzureBlobStorageAdapter) {
        return parent::url_stat($path, $flags);
      }
      $attributes = $adapter->stat((new WhitespacePathNormalizer())->normalizePath($this->getTarget()));
    }
    catch (AdapterConfigurationException | FilesystemException) {
      $attributes = NULL;
    }

    if ($attributes === NULL) {
      if (!($flags & STREAM_URL_STAT_QUIET)) {
        trigger_error("stat(): stat failed for $path", E_USER_WARNING);
      }
      return FALSE;
    }

    if ($attributes instanceof DirectoryAttributes) {
      return $this->buildStatForDirectory();
    }
    // Blob storage has no per-blob visibility.
    $mode = 0100000 | ($this->getSchemeVisibilityFallback() === Visibility::PUBLIC ? 0644 : 0600);

    return $this->buildStat(
      mode: $mode,
      size: $attributes->fileSize() ?? 0,
      mtime: $attributes->lastModified() ?? 0,
    );
  }

}
