<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs;

use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides Azure specific file system.
 *
 * Azure's NFS doesn't support any "normal" file operations (chmod for
 * example), making any request that performs them to fail, like
 * generating an image style.
 *
 * We check whether we're operating on Azure environment and
 * fallback to normal filesystem operations on any other environment.
 */
final class AzureFileSystem implements FileSystemInterface {

  /**
   * The file logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The inner service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected FileSystem $decorated;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\File\FileSystem $decorated
   *   The inner service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(FileSystem $decorated, StreamWrapperManagerInterface $streamWrapperManager, LoggerInterface $logger) {
    $this->decorated = $decorated;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function moveUploadedFile($filename, $uri) {
    return $this->decorated->moveUploadedFile($filename, $uri);
  }

  /**
   * Whether we're operating on Azure.
   *
   * @return bool
   *   TRUE if we're on Azure environment.
   */
  public function isAzure() : bool {
    return (bool) getenv('AZURE_SQL_SSL_CA_PATH');
  }

  /**
   * {@inheritdoc}
   */
  public function chmod($uri, $mode = NULL) {
    if (!$this->isAzure()) {
      return $this->decorated->chmod($uri, $mode);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri, $context = NULL) {
    // No need to override since chmod is only performed on
    // windows.
    return $this->decorated->unlink($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function realpath($uri) {
    return $this->decorated->realpath($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri) {
    return $this->decorated->dirname($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function basename($uri, $suffix = NULL) {
    return $this->decorated->basename($uri, $suffix);
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir(
    $uri,
    $mode = NULL,
    $recursive = FALSE,
    $context = NULL
  ) {
    if (!$this->isAzure()) {
      return $this->decorated->mkdir($uri, $mode, $recursive, $context);
    }

    // If the URI has a scheme, don't override the umask - schemes can handle
    // this issue in their own implementation.
    if (StreamWrapperManager::getScheme($uri)) {
      return $this->mkdirCall($uri, $recursive, $context);
    }

    // If recursive, create each missing component of the parent directory
    // individually and set the mode explicitly to override the umask.
    if ($recursive) {
      // Ensure the path is using DIRECTORY_SEPARATOR, and trim off any trailing
      // slashes because they can throw off the loop when creating the parent
      // directories.
      $uri = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $uri), DIRECTORY_SEPARATOR);
      // Determine the components of the path.
      $components = explode(DIRECTORY_SEPARATOR, $uri);
      // If the filepath is absolute the first component will be empty as there
      // will be nothing before the first slash.
      if ($components[0] == '') {
        $recursive_path = DIRECTORY_SEPARATOR;
        // Get rid of the empty first component.
        array_shift($components);
      }
      else {
        $recursive_path = '';
      }
      // Don't handle the top-level directory in this loop.
      array_pop($components);
      // Create each component if necessary.
      foreach ($components as $component) {
        $recursive_path .= $component;

        if (!file_exists($recursive_path)) {
          if (!$this->mkdirCall($recursive_path, FALSE, $context)) {
            return FALSE;
          }
          // Not necessary to use self::chmod() as there is no scheme.
          if (!chmod($recursive_path, $mode)) {
            return FALSE;
          }
        }

        $recursive_path .= DIRECTORY_SEPARATOR;
      }
    }

    // Do not check if the top-level directory already exists, as this condition
    // must cause this function to fail.
    if (!$this->mkdirCall($uri, FALSE, $context)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Helper function to create directories.
   *
   * Ensures we don't pass a NULL as a context resource to
   * mkdir() and override default mode to prevent any chmod
   * operations.
   *
   * @param string $uri
   *   A URI or pathname.
   * @param bool $recursive
   *   Create directories recursively, defaults to FALSE. Cannot work with a
   *   mode which denies writing or execution to the owner of the process.
   * @param resource $context
   *   Refer to http://php.net/manual/ref.stream.php.
   *
   * @return bool
   *   TRUE if succeeded.
   */
  protected function mkdirCall(string $uri, bool $recursive, $context): bool {
    if (is_null($context)) {
      return mkdir($uri, 0777, $recursive);
    }
    return mkdir($uri, 0777, $recursive, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $context = NULL) {
    // No need to override since chmod is only called on windows.
    return $this->decorated->rmdir($uri, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function tempnam($directory, $prefix) {
    return $this->decorated->tempnam($directory, $prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function copy($source, $destination, $replace = self::EXISTS_RENAME) {
    return $this->decorated->copy($source, $destination, $replace);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path) {
    return $this->decorated->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRecursive($path, callable $callback = NULL) {
    return $this->decorated->deleteRecursive($path, $callback);
  }

  /**
   * {@inheritdoc}
   */
  public function move($source, $destination, $replace = self::EXISTS_RENAME) {
    return $this->decorated->move($source, $destination, $replace);
  }

  /**
   * {@inheritdoc}
   */
  public function saveData(
    $data,
    $destination,
    $replace = self::EXISTS_RENAME
  ) {
    return $this->decorated->saveData($data, $destination, $replace);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDirectory(
    &$directory,
    $options = self::MODIFY_PERMISSIONS
  ) {
    return $this->decorated->prepareDirectory($directory, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function createFilename($basename, $directory) {
    return $this->decorated->createFilename($basename, $directory);
  }

  /**
   * {@inheritdoc}
   */
  public function getDestinationFilename($destination, $replace) {
    return $this->decorated->getDestinationFilename($destination, $replace);
  }

  /**
   * {@inheritdoc}
   */
  public function getTempDirectory() {
    return $this->decorated->getTempDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function scanDirectory($dir, $mask, array $options = []) {
    return $this->decorated->scanDirectory($dir, $mask, $options);
  }

}
