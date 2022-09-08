<?php

namespace Drupal\Tests\file\Functional;

use Drupal\Component\Utility\Environment;
use Drupal\file\Entity\File;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\file\Functional\FileManagedTestBase;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Tests the file transliteration.
 *
 * @group file
 */
class FileNameTransliterateTest extends FileManagedTestBase {
  use TestFileCreationTrait {
    getTestFiles as drupalGetTestFiles;
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['helfi_azure_fs', 'file'];

  /**
   * Array of files.
   *
   * @var array
   */
  private array $imageFiles;

  /**
   * Bad name for file.
   *
   * @var string
   */
  private string $brokenFileName = 'my test filename.png';

  /**
   * Proper name for file.
   *
   * @var string
   */
  private string $fixedFileName = 'my_test_filename.png';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->imageFiles = $this->drupalGetTestFiles('image');
  }

  /**
   * Tests file size upload errors.
   */
  public function testFileNameTransliteration() {
    $image_file = (array) current($this->imageFiles);
    $image_file['filename'] = $this->brokenFileName;
    $image = File::create($image_file);
    $image->save();
    $this->assertEquals($this->fixedFileName, $image->getFilename());
  }

}
