<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\helfi_azure_fs\BlobStorage;
use Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parts of the stream wrapper that don't connect to the storage.
 *
 * @see \Drupal\Tests\helfi_azure_fs\Kernel\AzureBlobStorageTest
 */
#[CoversClass(AzureStreamWrapper::class)]
#[Group('helfi_azure_fs')]
class AzureStreamWrapperTest extends UnitTestCase {

  /**
   * Gets the stream wrapper.
   *
   * @return \Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper
   *   The stream wrapper.
   */
  private function getSut() : AzureStreamWrapper {
    $container = new ContainerBuilder();
    $container->set(BlobStorage::class, new BlobStorage(new Settings([
      'helfi_azure_fs' => [
        'name' => 'account',
        'container' => 'container',
        'token' => 'token',
        'public_url_base' => 'https://account.blob.core.windows.net/container',
      ],
    ])));
    \Drupal::setContainer($container);

    return new AzureStreamWrapper();
  }

  /**
   * Tests the stream wrapper info.
   */
  public function testInfo() : void {
    $sut = $this->getSut();

    $this->assertSame(StreamWrapperInterface::NORMAL, AzureStreamWrapper::getType());
    $this->assertSame('Azure Blob Storage', $sut->getName()->getUntranslatedString());
    $this->assertSame('Files stored in Azure Blob Storage.', $sut->getDescription()->getUntranslatedString());
    $this->assertFalse($sut->realpath());

    $sut->setUri('azure://folder/file.txt');
    $this->assertSame('azure://folder/file.txt', $sut->getUri());
  }

  /**
   * Tests the directory names.
   */
  #[DataProvider('dirnameData')]
  public function testDirname(string $uri, string $expected) : void {
    $sut = $this->getSut();
    $this->assertSame($expected, $sut->dirname($uri));

    $sut->setUri($uri);
    $this->assertSame($expected, $sut->dirname());
  }

  /**
   * The data provider for testDirname().
   *
   * @return array<int, array{string, string}>
   *   The data.
   */
  public static function dirnameData() : array {
    return [
      ['azure://file.txt', 'azure://'],
      ['azure://folder/file.txt', 'azure://folder'],
      ['azure://a/b/c.txt', 'azure://a/b'],
      ['azure://a//b/', 'azure://a'],
    ];
  }

  /**
   * Tests the external URLs of the files.
   */
  public function testExternalUrl() : void {
    $sut = $this->getSut();
    $sut->setUri('azure://folder/file name.txt');

    $this->assertSame('https://account.blob.core.windows.net/container/folder/file%20name.txt', $sut->getExternalUrl());
  }

  /**
   * Tests that the created directories exist for the current request.
   */
  public function testMkdir() : void {
    $sut = $this->getSut();

    $this->assertTrue($sut->mkdir('azure://a/b', 0777, STREAM_MKDIR_RECURSIVE));

    foreach (['azure://a', 'azure://a/b'] as $uri) {
      $stat = $sut->url_stat($uri, 0);
      $this->assertIsArray($stat);
      $this->assertSame(0040777, $stat['mode']);
    }
  }

  /**
   * Tests the stream options that blob storage doesn't support.
   */
  public function testUnsupportedOptions() : void {
    $sut = $this->getSut();

    // Locking and flushing succeed, so they don't make the callers fail.
    $this->assertTrue($sut->stream_lock(LOCK_EX));
    $this->assertTrue($sut->stream_flush());
    $this->assertFalse($sut->stream_set_option(STREAM_OPTION_BLOCKING, 1, 0));
    $this->assertFalse($sut->stream_cast(STREAM_CAST_AS_STREAM));
    // Blob storage has no permissions or owners.
    $this->assertTrue($sut->stream_metadata('azure://file.txt', STREAM_META_ACCESS, 0644));
    $this->assertTrue($sut->stream_metadata('azure://file.txt', STREAM_META_OWNER, 1000));
  }

}
