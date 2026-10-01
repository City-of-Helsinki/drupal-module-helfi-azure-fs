<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Provides Azure specific file system.
 *
 * Azure's NFS doesn't support any "normal" file operations (chmod for
 * example), making any request that performs them to fail, like
 * when generating an image style.
 *
 * We check whether we're operating on Azure environment and
 * fallback to normal filesystem operations on any other environment.
 *
 * Everything else is delegated to the decorated service, which is the
 * Flysystem file system that handles the Flysystem schemes, like azure://.
 *
 * @see \Drupal\flysystem\FileSystem\FlysystemFileSystem
 */
final class AzureFileSystem implements FileSystemInterface {

  /**
   * Whether to skip FS operations or not.
   *
   * @var bool
   */
  private bool $skipFsOperations;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\File\FileSystemInterface $decorated
   *   The inner service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   */
  public function __construct(
    private readonly FileSystemInterface $decorated,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    Settings $settings,
  ) {
    $this->skipFsOperations = $settings::get('is_azure', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function chmod($uri, $mode = NULL) : bool {
    if (!$this->skipFsOperations) {
      // Let the decorated service use its own default mode. Flysystem's
      // file system doesn't accept NULL.
      return $mode === NULL ?
        $this->decorated->chmod($uri) :
        $this->decorated->chmod($uri, $mode);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir(
    $uri,
    $mode = NULL,
    $recursive = FALSE,
    $context = NULL,
  ): bool {
    if (!$this->skipFsOperations) {
      return $this->decorated->mkdir($uri, $mode, $recursive, $context);
    }

    // If the URI has a scheme, don't override the umask - schemes can handle
    // this issue in their own implementation.
    if ($this->streamWrapperManager::getScheme($uri)) {
      return $this->mkdirCall($uri, 0777, $recursive, $context);
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
          $success = $this->mkdirCall($recursive_path, 0777, FALSE, $context);
          // If the operation failed, check again if the directory was created
          // by another process/server, only report a failure if not.
          if (!$success && !file_exists($recursive_path)) {
            return FALSE;
          }
        }

        $recursive_path .= DIRECTORY_SEPARATOR;
      }
    }

    // Do not check if the top-level directory already exists, as this condition
    // must cause this function to fail.
    if (!$this->mkdirCall($uri, 0777, FALSE, $context)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Calls mkdir() without passing a NULL context resource.
   *
   * @param string $uri
   *   The URI.
   * @param int $mode
   *   The mode.
   * @param bool $recursive
   *   Whether to create the directories recursively.
   * @param resource|null $context
   *   The stream context.
   *
   * @return bool
   *   TRUE on success.
   *
   * @see \Drupal\Core\File\FileSystem::mkdirCall()
   */
  private function mkdirCall(string $uri, int $mode, bool $recursive, $context) : bool {
    if (is_null($context)) {
      return mkdir($uri, $mode, $recursive);
    }
    return mkdir($uri, $mode, $recursive, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function moveUploadedFile($filename, $uri) {
    return $this->decorated->moveUploadedFile($filename, $uri);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($uri, $context = NULL) {
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
   *
   * @param string $uri
   *   A URI or path.
   * @param string|null $suffix
   *   If the name component ends in suffix this will also be cut off.
   */
  public function basename($uri, $suffix = NULL) : string {
    return $this->decorated->basename($uri, $suffix);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($uri, $context = NULL) {
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
  public function copy($source, $destination, $fileExists = FileExists::Rename) {
    return $this->decorated->copy($source, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($path) : bool {
    return $this->decorated->delete($path);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRecursive($path, ?callable $callback = NULL) : bool {
    return $this->decorated->deleteRecursive($path, $callback);
  }

  /**
   * {@inheritdoc}
   */
  public function move($source, $destination, $fileExists = FileExists::Rename) {
    return $this->decorated->move($source, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function saveData($data, $destination, $fileExists = FileExists::Rename) {
    return $this->decorated->saveData($data, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareDirectory(&$directory, $options = self::MODIFY_PERMISSIONS) {
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
  public function getDestinationFilename($destination, $fileExists) {
    return $this->decorated->getDestinationFilename($destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function getTempDirectory() {
    return $this->decorated->getTempDirectory();
  }

  /**
   * {@inheritdoc}
   *
   * @param string $dir
   *   The base directory or URI to scan, without trailing slash.
   * @param string $mask
   *   The preg_match() regular expression for files to be included.
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return array<string, object>
   *   The files keyed by URI.
   */
  public function scanDirectory($dir, $mask, array $options = []) : array {
    return $this->decorated->scanDirectory($dir, $mask, $options);
  }

}
