<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\helfi_azure_fs\AzureFileSystem
 *
 * @group helfi_azure_fs
 */
class AzureFileSystemTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Gets the file system to test.
   *
   * @param \Prophecy\Prophecy\ObjectProphecy $decorated
   *   The prophesized FS.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   *
   * @return \Drupal\helfi_azure_fs\AzureFileSystem
   *   The SUT.
   */
  private function getSut(ObjectProphecy $decorated, Settings $settings) : AzureFileSystem {
    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    return new AzureFileSystem($decorated->reveal(), $streamWrapperManager, $settings, $logger);
  }

  /**
   * @covers ::chmod
   * @dataProvider chmodFolderData
   */
  public function testChmodSkipFsOperations(array $structure, string $uri) : void {
    vfsStream::setup('dir');
    vfsStream::create($structure);
    // Make sure decorated service is not called when 'skipFsOperations'
    // is enabled.
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->chmod($uri, NULL)->shouldNotBeCalled();

    $fs = $this->getSut($decorated, new Settings(['is_azure' => TRUE]));
    $this->assertTrue($fs->chmod($uri));

    // Test backward compatibility settings.
    putenv('OPENSHIFT_BUILD_NAMESPACE=123');
    $fs = $this->getSut($decorated, new Settings([]));
    $this->assertTrue($fs->chmod($uri));
  }

  /**
   * @covers ::chmod
   * @dataProvider chmodFolderData
   */
  public function testSkipOperationsFallback(array $structure, string $uri) : void {
    vfsStream::setup('dir');
    vfsStream::create($structure);

    // Make sure BC setting is not set.
    putenv('OPENSHIFT_BUILD_NAMESPACE=');
    // Make sure decorated service is called when 'skipFsOperations'
    // is disabled.
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->chmod($uri, NULL)
      ->shouldBeCalled()
      ->willReturn(TRUE);

    $this->getSut($decorated, new Settings([]))->chmod($uri);
  }

  /**
   * The data provider for chmod tests.
   *
   * @return array[]
   *   The data.
   */
  public function chmodFolderData() : array {
    return [
      [
        ['test.txt' => 'asdf'],
        'vfs://dir/test.txt',
      ],
      [
        ['nested_dir' => []],
        'vfs://dir/nested_dir',
      ],
    ];
  }

}
