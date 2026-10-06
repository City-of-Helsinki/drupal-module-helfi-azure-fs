<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\helfi_azure_fs\BlobStorage;
use Drupal\helfi_azure_fs\Controller\ImageStyleDownloadController;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\SecretsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the azure:// scheme against a real storage account.
 *
 * Requires the "flysystem_azure_connection_string" and
 * "flysystem_azure_container_name" secrets. See README.md.
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class AzureBlobStorageTest extends KernelTestBase {

  use SecretsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'image',
    'helfi_azure_fs',
  ];

  /**
   * The directory for the test files, unique for each test.
   */
  private string $directory;

  /**
   * The file system.
   */
  private FileSystemInterface $fileSystem;

  /**
   * The storage.
   */
  private BlobStorage $storage;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $connectionString = $this->getSecret('flysystem_azure_connection_string');
    $containerName = $this->getSecret('flysystem_azure_container_name');

    if (!$connectionString || !$containerName) {
      $this->fail('You must define "flysystem_azure_connection_string" and "flysystem_azure_container_name" secrets. See README.md.');
    }
    // The stream wrapper is registered when the container is built, so the
    // settings must be defined before.
    // The files are served from the blob endpoint of the connection string.
    if (!preg_match('/(?:^|;)BlobEndpoint=([^;]+)/', $connectionString, $matches)) {
      $this->fail('The "flysystem_azure_connection_string" secret must have a BlobEndpoint. See README.md.');
    }
    $this->setSetting('helfi_azure_fs', [
      'connectionString' => $connectionString,
      'container' => $containerName,
      'public_url_base' => rtrim($matches[1], '/') . '/' . $containerName,
    ]);
    $this->setSetting('file_additional_public_schemes', ['azure']);
    parent::register($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image']);

    $this->directory = 'test/' . $this->randomMachineName();
    $this->fileSystem = $this->container->get('file_system');
    $this->storage = $this->container->get(BlobStorage::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The kernel is shut down before this, which unregisters the stream
    // wrappers, so delete the test files with the storage directly.
    $this->deleteRecursive($this->directory);

    foreach (ImageStyle::loadMultiple() as $style) {
      $this->deleteRecursive(sprintf('styles/%s/azure/%s', $style->id(), $this->directory));
    }
    parent::tearDown();
  }

  /**
   * Deletes the given directory from the storage.
   *
   * @param string $path
   *   The directory.
   */
  private function deleteRecursive(string $path) : void {
    foreach ($this->storage->listDirectory($path) as $name) {
      $child = $path . '/' . $name;

      if ($this->storage->stat($child)['directory'] ?? FALSE) {
        $this->deleteRecursive($child);
        continue;
      }
      $this->storage->delete($child);
    }
  }

  /**
   * Gets the URI of the given path in the test directory.
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The URI.
   */
  private function uri(string $path = '') : string {
    return rtrim('azure://' . $this->directory . '/' . $path, '/');
  }

  /**
   * Tests stat() and its cache.
   */
  public function testStat() : void {
    file_put_contents($this->uri('folder/file.txt'), 'contents');

    $stat = $this->storage->stat($this->directory . '/folder/file.txt');
    $this->assertFalse($stat['directory'] ?? NULL);
    $this->assertSame(8, $stat['size'] ?? NULL);
    $this->assertGreaterThan(0, $stat['mtime'] ?? 0);
    $this->assertTrue($this->storage->stat($this->directory . '/folder')['directory'] ?? FALSE);
    $this->assertTrue($this->storage->stat('')['directory'] ?? FALSE);
    // Blobs that only start with the path don't exist.
    $this->assertNull($this->storage->stat($this->directory . '/folder/file'));
    $this->assertNull($this->storage->stat($this->directory . '/folder/missing.txt'));

    // The cache is cleared when the storage changes.
    file_put_contents($this->uri('folder/file.txt'), 'new contents');
    $this->assertSame(12, $this->storage->stat($this->directory . '/folder/file.txt')['size'] ?? NULL);

    unlink($this->uri('folder/file.txt'));
    $this->assertNull($this->storage->stat($this->directory . '/folder/file.txt'));
    $this->assertNull($this->storage->stat($this->directory . '/folder'));
  }

  /**
   * Tests the PHP file functions.
   */
  public function testStreamWrapper() : void {
    $file = $this->uri('folder/file.txt');

    $this->assertSame(5, file_put_contents($file, 'hello'));
    $this->assertSame('hello', file_get_contents($file));
    $this->assertSame(5, filesize($file));
    $this->assertTrue(is_file($file));
    $this->assertFalse(is_dir($file));
    $this->assertTrue(is_dir($this->uri('folder')));
    $this->assertTrue(is_dir('azure://'));
    $this->assertTrue(is_writable($this->uri('folder')));

    // Appending keeps the existing contents.
    $handle = fopen($file, 'a');
    $this->assertIsResource($handle);
    fwrite($handle, ' world');
    fclose($handle);
    $this->assertSame('hello world', file_get_contents($file));

    // Seeking and reading parts of the file.
    $handle = fopen($file, 'r');
    $this->assertIsResource($handle);
    fseek($handle, 6);
    $this->assertSame('world', fread($handle, 5));
    $this->assertTrue(feof($handle) || fread($handle, 1) === '');
    fclose($handle);

    // Exclusive creation fails for existing files.
    $this->assertFalse(@fopen($file, 'x'));
    // Reading missing files fails.
    $this->assertFalse(@fopen($this->uri('missing.txt'), 'r'));
    $this->assertFalse(@file_get_contents($this->uri('missing.txt')));
    $this->assertFalse(file_exists($this->uri('missing.txt')));

    $this->assertTrue(touch($this->uri('folder/empty.txt')));
    $this->assertSame(0, filesize($this->uri('folder/empty.txt')));

    $this->assertEqualsCanonicalizing(['empty.txt', 'file.txt'], scandir($this->uri('folder')));

    $this->assertTrue(rename($file, $this->uri('other/renamed.txt')));
    $this->assertFalse(file_exists($file));
    $this->assertSame('hello world', file_get_contents($this->uri('other/renamed.txt')));

    // Directories with files can't be removed.
    $this->assertFalse(@rmdir($this->uri('other')));
    $this->assertTrue(unlink($this->uri('other/renamed.txt')));
    $this->assertTrue(rmdir($this->uri('other')));
    $this->assertFalse(is_dir($this->uri('other')));

    // The created directories exist for the current request.
    $this->assertTrue(mkdir($this->uri('new/directory'), 0777, TRUE));
    $this->assertTrue(is_dir($this->uri('new/directory')));
  }

  /**
   * Tests the core file system operations.
   */
  public function testFileSystem() : void {
    // The destination directory doesn't have to be prepared.
    $file = $this->fileSystem->saveData('contents', $this->uri('a/b/file.txt'));
    $this->assertSame($this->uri('a/b/file.txt'), $file);
    $this->assertStringEqualsFile($file, 'contents');

    $directory = $this->uri('copies');
    $this->assertTrue($this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY));

    // Copying to a directory copies the file inside it.
    $this->assertSame($this->uri('copies/file.txt'), $this->fileSystem->copy($file, $directory));
    $this->assertSame($this->uri('copies/file_0.txt'), $this->fileSystem->copy($file, $directory));
    $this->assertSame($this->uri('copies/file.txt'), $this->fileSystem->copy($file, $this->uri('copies/file.txt'), FileExists::Replace));

    $moved = $this->fileSystem->move($this->uri('copies/file_0.txt'), $this->uri('moved/file.txt'));
    $this->assertSame($this->uri('moved/file.txt'), $moved);
    $this->assertFileDoesNotExist($this->uri('copies/file_0.txt'));
    $this->assertStringEqualsFile($moved, 'contents');

    $this->assertSame($this->uri('a/b'), $this->fileSystem->dirname($file));
    $this->assertSame('azure://', $this->fileSystem->dirname('azure://file.txt'));
    $this->assertFalse($this->fileSystem->realpath($file));

    $files = $this->fileSystem->scanDirectory($this->uri(), '/.*/');
    $this->assertEqualsCanonicalizing(
      [$this->uri('a/b/file.txt'), $this->uri('copies/file.txt'), $this->uri('moved/file.txt')],
      array_keys($files),
    );

    $this->fileSystem->deleteRecursive($this->uri());
    $this->assertSame([], $this->fileSystem->scanDirectory($this->uri(), '/.*/'));
  }

  /**
   * Tests copying and moving files between azure:// and the core schemes.
   */
  public function testCoreSchemes() : void {
    $public = $this->fileSystem->saveData('public contents', 'public://helfi-azure-fs-test.txt', FileExists::Replace);

    $copied = $this->fileSystem->copy($public, $this->uri('from-public.txt'));
    $this->assertStringEqualsFile($copied, 'public contents');
    $this->assertFileExists($public);

    $temporary = $this->fileSystem->copy($copied, 'temporary://helfi-azure-fs-test.txt', FileExists::Replace);
    $this->assertStringEqualsFile($temporary, 'public contents');

    $moved = $this->fileSystem->move($copied, 'public://helfi-azure-fs-moved.txt', FileExists::Replace);
    $this->assertStringEqualsFile($moved, 'public contents');
    $this->assertFileDoesNotExist($copied);

    foreach ([$public, $temporary, $moved] as $uri) {
      $this->fileSystem->delete($uri);
    }
  }

  /**
   * Tests the file and image style URLs.
   */
  public function testUrls() : void {
    $generator = $this->container->get('file_url_generator');
    $image = $this->fileSystem->copy($this->root . '/core/tests/fixtures/files/image-1.png', $this->uri('image 1.png'));

    $this->assertSame($this->storage->getPublicUrl($this->directory . '/image 1.png'), $generator->generateAbsoluteString($image));
    $this->assertStringEndsWith('/image%201.png', $generator->generateAbsoluteString($image));

    // Missing derivatives are generated by Drupal.
    $style = ImageStyle::load('thumbnail');
    $derivative = $style->buildUri($image);
    $this->assertStringContainsString('/styles/thumbnail/azure/' . $this->directory . '/image%201.png', $generator->generateAbsoluteString($derivative));
    $this->assertStringNotContainsString($this->storage->getPublicUrl(''), $generator->generateAbsoluteString($derivative));

    // Existing derivatives are served from the blob storage.
    $this->assertTrue($style->createDerivative($image, $derivative));
    $this->assertSame($this->storage->getPublicUrl((string) StreamWrapperManager::getTarget($derivative)), $generator->generateAbsoluteString($derivative));
  }

  /**
   * Tests that the derivatives are generated and redirected to.
   */
  public function testImageStyleController() : void {
    $style = ImageStyle::load('thumbnail');
    $controller = ImageStyleDownloadController::create($this->container);

    // Each file is redirected to its own derivative.
    foreach (['first.png', 'second.png'] as $name) {
      $image = $this->fileSystem->copy($this->root . '/core/tests/fixtures/files/image-1.png', $this->uri($name));
      $request = Request::create('/', 'GET', [
        'file' => $this->directory . '/' . $name,
        IMAGE_DERIVATIVE_TOKEN => $style->getPathToken($image),
      ]);
      $response = $controller->deliver($request, 'azure', $style, 'azure');

      $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
      $this->assertSame(302, $response->getStatusCode());
      $this->assertSame($this->storage->getPublicUrl((string) StreamWrapperManager::getTarget($style->buildUri($image))), $response->getTargetUrl());
      // The route doesn't vary by the file, so the redirect is not cached.
      $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
      $this->assertFileExists($style->buildUri($image));
    }
  }

}
