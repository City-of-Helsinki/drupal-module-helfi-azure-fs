<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;

/**
 * Tests AzureFileSystem.
 *
 * @group helfi_azure_fs
 */
class AzureFileSystemTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Mocks stream wrapper manager.
   *
   * @param bool $expectedValue
   *   The expected return value.
   *
   * @return \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   *   The stream wrapper mock.
   */
  private function getStreamWrapperManagerMock(bool $expectedValue) : StreamWrapperManagerInterface {
    return new class($expectedValue) extends StreamWrapperManager {

      // phpcs:ignore
      private static bool $expectedReturnValue;

      /**
       * Constructs a new instance.
       *
       * @param bool $value
       *   The value.
       */
      public function __construct(bool $value) {
        static::$expectedReturnValue = $value;
      }

      /**
       * {@inheritdoc}
       */
      public static function getScheme($uri) {
        return static::$expectedReturnValue;
      }

    };
  }

  /**
   * Asserts that the file permissions of a given URI matches.
   *
   * @param int $expected_mode
   *   The expected file mode.
   * @param string $uri
   *   The URI to test.
   * @param string $message
   *   An optional error message.
   *
   * @internal
   */
  private function assertFilePermissions(int $expected_mode, string $uri, string $message = ''): void {
    // Mask out all but the last three octets.
    $actual_mode = fileperms($uri) & 0777;
    $this->assertSame($expected_mode, $actual_mode, $message);
  }

  /**
   * Gets the file system to test.
   *
   * @param \Drupal\Core\File\FileSystemInterface $decorated
   *   The prophesized FS.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface|null $streamWrapperManager
   *   The stream wrapper manager.
   *
   * @return \Drupal\helfi_azure_fs\AzureFileSystem
   *   The SUT.
   */
  private function getSut(FileSystemInterface $decorated, Settings $settings, ?StreamWrapperManagerInterface $streamWrapperManager = NULL) : AzureFileSystem {
    if (!$streamWrapperManager) {
      $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    }
    return new AzureFileSystem($decorated, $streamWrapperManager, $settings);
  }

  /**
   * Tests chmod.
   *
   * @dataProvider chmodFolderData
   */
  public function testChmodSkipFsOperations(array $structure, string $uri) : void {
    vfsStream::setup('dir');
    vfsStream::create($structure);
    // Make sure decorated service is not called when 'skipFsOperations'
    // is enabled.
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->chmod($uri, NULL)->shouldNotBeCalled();

    $fs = $this->getSut($decorated->reveal(), new Settings(['is_azure' => TRUE]));
    $this->assertTrue($fs->chmod($uri));
  }

  /**
   * Tests fallback operation.
   *
   * @dataProvider chmodFolderData
   */
  public function testSkipOperationsFallback(array $structure, string $uri) : void {
    vfsStream::setup('dir');
    vfsStream::create($structure);

    // Make sure decorated service is called when 'skipFsOperations'
    // is disabled.
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->chmod($uri, NULL)
      ->shouldBeCalled()
      ->willReturn(TRUE);

    $this->getSut($decorated->reveal(), new Settings([]))->chmod($uri);
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

  /**
   * Tests mkdir with skipFsOperations.
   *
   * @dataProvider chmodFolderData
   */
  public function testMkdirSkipFsOperations(array $structure, string $uri) : void {
    vfsStream::setup('dir');
    vfsStream::create($structure);

    // Make sure decorated service is called when 'skipFsOperations'
    // is disabled.
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->mkdir($uri, NULL, FALSE, NULL)
      ->shouldBeCalled()
      ->willReturn(TRUE);

    $this->getSut($decorated->reveal(), new Settings([]))->mkdir($uri);
  }

  /**
   * Tests mkdir with scheme.
   */
  public function testMkdirWithScheme() : void {
    $streamWrapperManager = $this->getStreamWrapperManagerMock(TRUE);
    $uri = 'vfs://dir/subdir';
    $decorated = $this->prophesize(FileSystemInterface::class);
    $decorated->mkdir($uri, NULL, FALSE, NULL)
      ->shouldNotBeCalled();
    $sut = $this->getSut($decorated->reveal(), new Settings(['is_azure' => TRUE]), $streamWrapperManager);
    $this->assertTrue($sut->mkdir($uri));
    $this->assertTrue(file_exists($uri));
    $this->assertFilePermissions(0777, $uri);
  }

  /**
   * Tests mkdir without skipFsOperations.
   */
  public function testMkDirNoScheme() : void {
    $streamWrapperManager = $this->getStreamWrapperManagerMock(FALSE);
    vfsStream::setup('dir');
    $decorated = $this->prophesize(FileSystem::class);
    $decorated->mkdir(Argument::any(), Argument::any(), Argument::any(), Argument::any())
      ->shouldNotBeCalled();

    $settings = new Settings(['is_azure' => TRUE]);
    $sut = $this->getSut($decorated->reveal(), $settings, $streamWrapperManager);

    $uri = 'vfs://dir/subdir/';
    $this->assertTrue($sut->mkdir($uri));
    $this->assertTrue(file_exists($uri));
    $this->assertFilePermissions(0777, $uri);

    $uri = 'vfs://dir/subdir/subdir2';
    $this->assertTrue($sut->mkdir($uri, recursive: TRUE));
    $this->assertTrue(file_exists($uri));
    $this->assertFilePermissions(0777, $uri);
  }

}
