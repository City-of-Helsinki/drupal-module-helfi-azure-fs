<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Plugin\Flysystem\Adapter\Azure;
use Drupal\Tests\helfi_api_base\Traits\SecretsTrait;
use Drupal\Tests\UnitTestCase;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Azure blob storage adapter against a real storage account.
 */
#[Group('helfi_azure_fs')]
class AzureBlobStorageTest extends UnitTestCase {

  use SecretsTrait;

  /**
   * The file system.
   *
   * @var \League\Flysystem\Filesystem
   */
  protected Filesystem $filesystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $connectionString = $this->getSecret('flysystem_azure_connection_string');
    $container = $this->getSecret('flysystem_azure_container_name');

    if (!$connectionString || !$container) {
      $this
        ->fail('You must define "flysystem_azure_connection_string" and "flysystem_azure_container_name" secrets. See README.md.');
    }
    $this->filesystem = $this->getFilesystem([
      'container' => $container,
      'connectionString' => $connectionString,
    ]);
  }

  /**
   * Constructs a filesystem.
   *
   * @param array<string, string> $configuration
   *   The driver configuration.
   *
   * @return \League\Flysystem\Filesystem
   *   The filesystem.
   */
  private function getFilesystem(array $configuration): Filesystem {
    $adapter = (new Azure([], 'helfi_azure', []))->buildAdapter($configuration);
    return new Filesystem($adapter);
  }

  /**
   * Constructs a filesystem where every request fails.
   *
   * @return \League\Flysystem\Filesystem
   *   The filesystem.
   */
  private function getSutWithException(): Filesystem {
    return $this->getFilesystem([
      'container' => 'invalid',
      // Points to a local storage emulator that is not running.
      // @see \AzureOss\Storage\Common\Helpers\ConnectionStringHelper
      'connectionString' => 'UseDevelopmentStorage=true',
    ]);
  }

  /**
   * Tests write and read.
   */
  public function testWritingAndReadingFile(): void {
    $filename = 'test/file.txt';
    $contents = 'contents';
    $this->filesystem->write($filename, $contents);
    $this->assertTrue($this->filesystem->fileExists($filename));
    $this->assertEquals($contents, $this->filesystem->read($filename));
    $this->filesystem->delete($filename);
    $this->assertFalse($this->filesystem->fileExists($filename));
  }

  /**
   * Tests that a failing write throws.
   */
  public function testWriteErrors(): void {
    $this->expectException(UnableToWriteFile::class);
    $this->getSutWithException()->write('filename.txt', 'contents');
  }

  /**
   * Tests that reading a missing file throws.
   */
  public function testReadErrors(): void {
    $this->expectException(UnableToReadFile::class);
    $this->filesystem->read('not-existing.txt');
  }

  /**
   * Tests overwriting an existing file.
   */
  public function testOverwritingFile(): void {
    $filename = 'test/file.txt';
    $contents = 'new contents';
    $this->filesystem->write($filename, 'original contents');
    $this->filesystem->write($filename, $contents);
    $this->assertEquals($contents, $this->filesystem->read($filename));
    $this->filesystem->delete($filename);
    $this->assertFalse($this->filesystem->fileExists($filename));
  }

  /**
   * Tests writeStream() and readStream().
   */
  public function testWritingAndReadingStream(): void {
    $filename = 'test/file.txt';
    $handle = fopen('php://temp', 'w+b');
    $this->assertIsResource($handle);
    fwrite($handle, 'contents');
    rewind($handle);
    $this->filesystem->writeStream($filename, $handle);

    $handle = $this->filesystem->readStream($filename);
    $this->assertIsResource($handle);
    $this->assertEquals('contents', stream_get_contents($handle));

    $this->filesystem->delete($filename);
    $this->assertFalse($this->filesystem->fileExists($filename));
  }

  /**
   * Make sure deleting a missing file doesn't throw.
   */
  public function testDeletingFilesThatDontExist(): void {
    $this->filesystem->delete('test/non-existent-filename.txt');
    $this->assertFalse($this->filesystem->fileExists('test/non-existent-filename.txt'));
  }

  /**
   * Tests copy().
   */
  public function testCopyingFiles(): void {
    $this->filesystem->write('test/source.txt', 'contents');
    $this->filesystem->copy('test/source.txt', 'test/destination.txt');
    $this->assertTrue($this->filesystem->fileExists('test/destination.txt'));
    $this->assertEquals('contents', $this->filesystem->read('test/destination.txt'));

    $this->filesystem->delete('test/source.txt');
    $this->filesystem->delete('test/destination.txt');
  }

  /**
   * Tests move().
   */
  public function testMovingFile(): void {
    $this->filesystem->write('test/path/to/file.txt', 'contents');
    $this->filesystem->move('test/path/to/file.txt', 'test/new/path.txt');
    $this->assertTrue($this->filesystem->fileExists('test/new/path.txt'));
    $this->assertFalse($this->filesystem->fileExists('test/path/to/file.txt'));

    $this->filesystem->delete('test/new/path.txt');
    $this->assertFalse($this->filesystem->fileExists('test/new/path.txt'));
  }

  /**
   * Tests directories and listContents().
   */
  public function testListingDirectory(): void {
    // Directories are virtual, so creating one is a no-op.
    $this->filesystem->createDirectory('dirname');

    $this->filesystem->write('test/path/to/file.txt', 'a file');
    $this->filesystem->write('test/path/to/another/file.txt', 'a file');
    $this->assertTrue($this->filesystem->directoryExists('test/path/to'));
    $this->assertCount(2, $this->filesystem->listContents('test/path/to')->toArray());
    $this->assertCount(3, $this->filesystem->listContents('test/path/to', TRUE)->toArray());
    $this->assertCount(4, $this->filesystem->listContents('test/path', TRUE)->toArray());

    $paths = $this->filesystem->listContents('test/path/to')
      ->map(fn (StorageAttributes $item) => $item->path())
      ->toArray();
    $this->assertEqualsCanonicalizing(['test/path/to/file.txt', 'test/path/to/another'], $paths);

    $this->filesystem->deleteDirectory('test/path/to');
    $this->assertFalse($this->filesystem->fileExists('test/path/to/file.txt'));
    $this->assertFalse($this->filesystem->fileExists('test/path/to/another/file.txt'));
    $this->assertFalse($this->filesystem->directoryExists('test/path/to'));
  }

  /**
   * Tests that a failing listing throws.
   */
  public function testListingErrors(): void {
    $this->expectException(UnableToListContents::class);
    $this->getSutWithException()->listContents('test')->toArray();
  }

  /**
   * Test metadata getters.
   */
  public function testMetadataGetters(): void {
    $filename = 'test/file.txt';
    $this->filesystem->write($filename, 'contents');
    $this->assertIsInt($this->filesystem->lastModified($filename));
    $this->assertEquals(8, $this->filesystem->fileSize($filename));
    $this->assertEquals('text/plain', $this->filesystem->mimeType($filename));

    $this->filesystem->delete($filename);
    $this->assertFalse($this->filesystem->fileExists($filename));
  }

  /**
   * Tests stat().
   */
  public function testStat(): void {
    $adapter = (new Azure([], 'helfi_azure', []))->buildAdapter([
      'container' => $this->getSecret('flysystem_azure_container_name'),
      'connectionString' => $this->getSecret('flysystem_azure_connection_string'),
    ]);
    $this->filesystem->write('test/stat/file.txt', 'contents');

    $attributes = $adapter->stat('test/stat/file.txt');
    $this->assertInstanceOf(FileAttributes::class, $attributes);
    $this->assertEquals(8, $attributes->fileSize());
    $this->assertIsInt($attributes->lastModified());
    $this->assertInstanceOf(DirectoryAttributes::class, $adapter->stat('test/stat'));
    $this->assertInstanceOf(DirectoryAttributes::class, $adapter->stat(''));
    // Blobs that only start with the path don't exist.
    $this->assertNull($adapter->stat('test/stat/file'));
    $this->assertNull($adapter->stat('test/stat/missing.txt'));

    // The results are cached until the adapter changes something.
    $this->assertSame($attributes, $adapter->stat('test/stat/file.txt'));
    $this->filesystem->write('test/stat/file.txt', 'new contents');
    $attributes = $adapter->stat('test/stat/file.txt');
    $this->assertEquals(12, $attributes->fileSize());

    $this->filesystem->delete('test/stat/file.txt');
    $this->assertNull($adapter->stat('test/stat/file.txt'));
    $this->assertNull($adapter->stat('test/stat'));
  }

  /**
   * Tests that failing stat requests throw.
   */
  public function testStatErrors(): void {
    $adapter = (new Azure([], 'helfi_azure', []))->buildAdapter([
      'container' => 'invalid',
      'connectionString' => 'UseDevelopmentStorage=true',
    ]);
    $this->expectException(UnableToRetrieveMetadata::class);
    $adapter->stat('test/file.txt');
  }

  /**
   * Tests that failing metadata requests throw.
   */
  public function testMetadataErrors(): void {
    $this->expectException(UnableToRetrieveMetadata::class);
    $this->getSutWithException()->fileSize('test/file.txt');
  }

}
