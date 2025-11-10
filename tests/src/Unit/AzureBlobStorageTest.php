<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Flysystem\Azure;
use Drupal\Tests\UnitTestCase;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Tests Azure blob storage adapter.
 *
 * @group helfi_azure_fs
 */
class AzureBlobStorageTest extends UnitTestCase {

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

    $connectionString = getenv('FLYSYSTEM_AZURE_CONNECTION_STRING');
    $container = getenv('FLYSYSTEM_AZURE_CONTAINER_NAME') ?: 'etusivu64e62test';

    $configuration = [
      'container' => $container,
      'connectionString' => $connectionString,

    ];
    $adapter = (new Azure($configuration, $this->prophesize(LoggerInterface::class)->reveal()))
      ->getAdapter();
    $this->filesystem = new Filesystem($adapter);
    $this->filesystem->getConfig()->set('disable_asserts', TRUE);
  }

  /**
   * Tests write and read.
   */
  public function testWritingAndReadingFile(): void {
    $contents = 'with contents';
    $filename = 'test/a_file.txt';
    $this->assertTrue($this->filesystem->write($filename, $contents));
    $this->assertEquals($contents, $this->filesystem->read($filename));
    $this->assertTrue($this->filesystem->delete($filename));
    $this->assertFalse($this->filesystem->has($filename));
  }

  /**
   * Tests read with non-existing file.
   */
  public function testReadErrors(): void {
    $this->assertFalse($this->filesystem->read('not-existing.txt'));
  }

  /**
   * Make sure we can update the file.
   */
  public function testWritingAndUpdatingAndReadingFile(): void {
    $contents = 'new contents';
    $filename = 'test/a_file.txt';
    $this->assertTrue($this->filesystem->write($filename, 'original contents'));
    $this->assertTrue($this->filesystem->update($filename, $contents));
    $this->assertEquals($contents, $this->filesystem->read($filename));
    $this->assertTrue($this->filesystem->delete($filename));
    $this->assertFalse($this->filesystem->has($filename));
  }

  /**
   * Tests writeStream() and readStream().
   */
  public function testWritingAndReadingStream(): void {
    $contents = 'with contents';
    $filename = 'test/a_file.txt';
    $handle = tmpfile();
    fwrite($handle, $contents);
    $this->assertTrue($this->filesystem->writeStream($filename, $handle));
    is_resource($handle) && fclose($handle);
    $handle = $this->filesystem->readStream($filename);
    $this->assertIsResource($handle);
    $this->assertEquals($contents, stream_get_contents($handle));

    $contents = 'with contents 2';
    $handle = tmpfile();
    fwrite($handle, $contents);
    $this->assertTrue($this->filesystem->updateStream($filename, $handle));
    is_resource($handle) && fclose($handle);
    $handle = $this->filesystem->readStream($filename);
    $this->assertIsResource($handle);
    $this->assertEquals($contents, stream_get_contents($handle));

    $this->assertTrue($this->filesystem->delete($filename));
    $this->assertFalse($this->filesystem->has($filename));
  }

  /**
   * Make sure we can delete files that don't exist.
   */
  public function testDeletingFilesThatDontExist(): void {
    $this->assertTrue($this->filesystem->delete('test/non-existent-filename.txt'));
  }

  /**
   * Tests copy().
   */
  public function testCopyingFiles(): void {
    $this->assertNotFalse($this->filesystem->write('test/source.txt', 'contents'));
    $this->filesystem->copy('test/source.txt', 'test/destination.txt');
    $this->assertTrue($this->filesystem->has('test/destination.txt'));
    $this->assertEquals('contents', $this->filesystem->read('test/destination.txt'));
  }

  /**
   * Tests creating a new directory.
   */
  public function testCreatingDirectory(): void {
    $this->assertTrue($this->filesystem->createDir('dirname'));
  }

  /**
   * Tests listContents().
   */
  public function testListingDirectory(): void {
    $this->filesystem->write('test/path/to/file.txt', 'a file');
    $this->filesystem->write('test/path/to/another/file.txt', 'a file');
    $this->assertCount(2, $this->filesystem->listContents('test/path/to'));
    $this->assertCount(3, $this->filesystem->listContents('test/path/to', TRUE));
    $this->assertCount(4, $this->filesystem->listContents('test/path', TRUE));

    $this->assertTrue($this->filesystem->deleteDir('test/path/to'));
    $this->assertFalse($this->filesystem->has('test/path/to/file.txt'));
    $this->assertFalse($this->filesystem->has('test/path/to/another/file.txt'));
  }

  /**
   * Test metadata getters.
   */
  public function testMetadataGetters(): void {
    $filename = 'test/file.txt';
    $this->filesystem->write($filename, 'contents');
    $this->assertIsInt($this->filesystem->getTimestamp($filename));
    $this->assertIsArray($this->filesystem->getMetadata($filename));
    $this->assertIsInt($this->filesystem->getSize($filename));
    $this->assertIsString($this->filesystem->getMimetype($filename));

    $this->filesystem->delete($filename);
    $this->assertFalse($this->filesystem->has($filename));
  }

  /**
   * Make sure we can rename a file.
   */
  public function testRenamingFile(): void {
    $this->filesystem->write('test/path/to/file.txt', 'contents');
    $this->filesystem->rename('test/path/to/file.txt', 'test/new/path.txt');
    $this->assertTrue($this->filesystem->has('test/new/path.txt'));
    $this->assertFalse($this->filesystem->has('test/path/to/file.txt'));

    $this->filesystem->delete('test/new/path.txt');
    $this->assertFalse($this->filesystem->has('test/new/path.txt'));
  }

}
