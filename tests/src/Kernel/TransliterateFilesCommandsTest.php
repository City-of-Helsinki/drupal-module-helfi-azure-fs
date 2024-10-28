<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\field\Kernel\FieldKernelTestBase;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\helfi_azure_fs\Drush\Commands\TransliterateFilesCommands;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;

/**
 * Tests transliterate file Drush command.
 *
 * @group helfi_azure_fs
 */
class TransliterateFilesCommandsTest extends FieldKernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'image',
    'file',
    'helfi_azure_fs',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();
    $this->installConfig(['file', 'helfi_azure_fs']);
    $this->installEntitySchema('file');
  }

  /**
   * Make sure files are transliterated.
   */
  public function testTransliterateFilesCommand() : void {
    /** @var \Drupal\helfi_azure_fs\AzureFileSystem $fileSystem */
    $fileSystem = $this->container->get('file_system');
    /** @var \Drupal\file\FileStorage $fileStorage */
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');
    $this->assertInstanceOf(AzureFileSystem::class, $fileSystem);

    $files = [
      'public://folder/Jöö.jpg' => 'public://folder/joo.jpg',
      'public://folder/fine.jpg' => 'public://folder/fine.jpg',
      'public://Jöö.jpg' => 'public://joo.jpg',
      'public://fine.jpg' => 'public://fine.jpg',
    ];
    foreach ($files as $file => $expected) {
      $dir = $fileSystem->dirname($file);
      $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
      $fileSystem->saveData('', $file);
      $this->assertFileExists($file);

      $fileStorage->create([
        'uri' => $file,
      ])->save();
    }

    // Make sure file transliteration is enabled.
    \Drupal::configFactory()->getEditable('file.settings')
      ->set('filename_sanitization', [
        'transliterate' => TRUE,
        'replace_whitespace' => TRUE,
        'replace_non_alphanumeric' => TRUE,
        'deduplicate_separators' => TRUE,
        'lowercase' => TRUE,
        'replacement_character' => '_',
      ])->save();

    $command = TransliterateFilesCommands::create($this->container);
    $command->transliterate();

    foreach ($files as $expected) {
      $this->assertFileExists($expected);
      // Make sure uri and filename were updated too.
      $files = $fileStorage->loadByProperties(['uri' => $expected]);
      $this->assertCount(1, $files);
      $file = reset($files);
      $this->assertEquals($fileSystem->basename($expected), $file->getFilename());
    }
  }

}
