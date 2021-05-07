<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\Site\Settings;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\Tests\Core\File\FileSystemTest as CoreFileSystemTest;

/**
 * @coversDefaultClass \Drupal\helfi_azure_fs\AzureFileSystem
 *
 * @group helfi_azure_fs
 */
class FileSystemTest extends CoreFileSystemTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->fileSystem = new AzureFileSystem($this->fileSystem, $this->streamWrapperManager, new Settings([]), $this->logger);
  }

}
