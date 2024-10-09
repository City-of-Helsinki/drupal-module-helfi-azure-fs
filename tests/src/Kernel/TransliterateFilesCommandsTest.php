<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\field\Kernel\FieldKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\helfi_azure_fs\AzureFileSystem;
use Drupal\helfi_azure_fs\Drush\Commands\TransliterateFilesCommands;

/**
 * Tests transliterate file Drush command.
 *
 * @group helfi_azure_fs
 */
class TransliterateFilesCommandsTest extends FieldKernelTestBase {

  use TestFileCreationTrait;

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
   * Enable/disable transliterate settings.
   *
   * @param bool $enabled
   *   Whether to enable file sanitization.
   */
  private function setTransliterateSetting(bool $enabled) : void {
    \Drupal::configFactory()->getEditable('file.settings')
      ->set('filename_sanitization', [
        'transliterate' => $enabled,
        'replace_whitespace' => TRUE,
        'replace_non_alphanumeric' => TRUE,
        'deduplicate_separators' => TRUE,
        'lowercase' => TRUE,
        'replacement_character' => '_',
      ])->save();
  }

  /**
   * Make sure files are transliterated.
   */
  public function testTransliterateFilesCommand() : void {
    /** @var \Drupal\helfi_azure_fs\AzureFileSystem $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $this->assertInstanceOf(AzureFileSystem::class, $fileSystem);

    // Make sure transliterate setting is enabled.
    $this->setTransliterateSetting(FALSE);

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

      File::create([
        'uri' => $file,
      ])->save();
    }

    $this->setTransliterateSetting(TRUE);
    $command = new TransliterateFilesCommands(
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('event_dispatcher'),
      $this->container->get('entity_type.manager'),
      $fileSystem,
    );
    $command->transliterate();

    foreach ($files as $file => $expected) {
      $this->assertFileExists($expected);
    }
  }

}
