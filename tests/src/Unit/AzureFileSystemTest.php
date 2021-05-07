<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\Site\Settings;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\Tests\Core\File\FileSystemTest as CoreFileSystemTest;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\helfi_azure_fs\AzureFileSystem
 *
 * @group helfi_azure_fs
 */
class AzureFileSystemTest extends CoreFileSystemTest {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    putenv('AZURE_SQL_SSL_CA_PATH=/var/www');
    $this->fileSystem = new AzureFileSystem($this->fileSystem, $this->streamWrapperManager, new Settings([]), $this->logger);
  }

  /**
   * @covers ::chmod
   */
  public function testChmodFile() {
    vfsStream::setup('dir');
    vfsStream::create(['test.txt' => 'asdf']);
    $uri = 'vfs://dir/test.txt';

    // Make sure chmoding file doesn't actually do anything.
    $this->assertTrue($this->fileSystem->chmod($uri));
    $this->assertFilePermissions(0666, $uri);
    $this->assertTrue($this->fileSystem->chmod($uri, 0444));
    $this->assertFilePermissions(0666, $uri);
  }

  /**
   * @covers ::chmod
   */
  public function testChmodDir() {
    vfsStream::setup('dir');
    vfsStream::create(['nested_dir' => []]);
    $uri = 'vfs://dir/nested_dir';

    // Make sure chmoding directory doesn't actually do anything.
    $this->assertTrue($this->fileSystem->chmod($uri));
    $this->assertFilePermissions(0777, $uri);
    $this->assertTrue($this->fileSystem->chmod($uri, 0444));
    $this->assertFilePermissions(0777, $uri);
  }

  /**
   * @covers ::chmod
   */
  public function testChmodUnsuccessful() {
    // Override the test because it's not supposed to ever fail.
  }

}
