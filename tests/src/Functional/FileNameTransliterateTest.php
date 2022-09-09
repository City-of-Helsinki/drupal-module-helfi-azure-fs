<?php

namespace Drupal\Tests\file\Functional;

/**
 * Tests the file transliteration.
 *
 * @group file
 */
class FileNameTransliterateTest extends FileManagedTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['helfi_azure_fs', 'file'];

  /**
   * Tests name transliteration.
   */
  public function testFileNameTransliteration() {
    $image = $this->createFile(NULL, $this->randomMachineName());

    $brokenFileName = 'my test filename.png';
    $fixedFileName = 'my_test_filename.png';

    $image->setFilename($brokenFileName);
    $image->save();

    $this->assertEquals($fixedFileName, $image->getFilename());

    $image2 = $this->createFile(NULL, $this->randomMachineName());
    $image2->setFilename($brokenFileName);
    $image2->save();

    $this->assertEquals('my_test_filename_0.png', $image2->getFilename());
  }

}
