<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides Azure specific file system.
 *
 * Azure's NFS doesn't support any "normal" file operations (chmod for
 * example), making any request that performs them to fail, like
 * when generating an image style.
 *
 * We check whether we're operating on Azure environment and
 * fallback to normal filesystem operations on any other environment.
 */
final class AzureFileSystem extends FileSystem {

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
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private FileSystemInterface $decorated,
    StreamWrapperManagerInterface $streamWrapperManager,
    Settings $settings,
    LoggerInterface $logger,
  ) {
    $this->skipFsOperations = $settings::get('is_azure', FALSE);

    parent::__construct($streamWrapperManager, $settings, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function chmod($uri, $mode = NULL) : bool {
    if (!$this->skipFsOperations) {
      return $this->decorated->chmod($uri, $mode);
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
    $context = NULL
  ): bool {
    if (!$this->skipFsOperations) {
      return $this->decorated->mkdir($uri, $mode, $recursive, $context);
    }

    // If the URI has a scheme, don't override the umask - schemes can handle
    // this issue in their own implementation.
    if (StreamWrapperManager::getScheme($uri)) {
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
          $success = $this->mkdirCall($recursive_path, $mode, FALSE, $context);
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

}
