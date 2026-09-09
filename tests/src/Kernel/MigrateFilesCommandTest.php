<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Tests\field\Kernel\FieldKernelTestBase;
use Drupal\file\FileInterface;
use Drupal\helfi_azure_fs\Drush\Commands\MigrateFilesCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests the migrate files Drush command.
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class MigrateFilesCommandTest extends FieldKernelTestBase {

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

    // Use Drupal core's 'temporary' stream wrapper as a stand-in for
    // 'azure' so the migration can be tested without a real Azure
    // connection.
    \Drupal::configFactory()->getEditable('helfi_azure_fs.settings')
      ->set('use_blob_storage', TRUE)
      ->set('storage_scheme', 'temporary')
      ->save();

    // The 'temporary://' stream wrapper points to the real system temporary
    // directory, which can contain a leftover file from a previous test run.
    @unlink('temporary://file.txt');
    @unlink('temporary://missing.txt');
  }

  /**
   * Make sure local public files are migrated to Blob storage.
   */
  public function testMigrateFiles() : void {
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    /** @var \Drupal\file\FileStorageInterface $fileStorage */
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');

    $fileSystem->saveData('data', 'public://file.txt');
    $file = $fileStorage->create([
      'uri' => 'public://file.txt',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();

    $command = MigrateFilesCommand::create($this->container);
    $exitCode = $command->run(new ArrayInput([]), new NullOutput());
    $this->assertEquals(MigrateFilesCommand::SUCCESS, $exitCode);

    $this->assertFileExists('temporary://file.txt');

    $file = $fileStorage->load($file->id());
    $this->assertEquals('temporary://file.txt', $file->getFileUri());
  }

  /**
   * Make sure --use-existing reuses a file already on Blob storage.
   */
  public function testMigrateFilesUsesExistingAzureFile() : void {
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    /** @var \Drupal\file\FileStorageInterface $fileStorage */
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');

    // The local copy is missing entirely, but an identically named file
    // already exists at the destination on Blob storage.
    $file = $fileStorage->create([
      'uri' => 'public://missing.txt',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();
    $fileSystem->saveData('azure-data', 'temporary://missing.txt');

    $command = MigrateFilesCommand::create($this->container);
    $exitCode = $command->run(new ArrayInput(['--use-existing' => TRUE]), new NullOutput());
    $this->assertEquals(MigrateFilesCommand::SUCCESS, $exitCode);

    $file = $fileStorage->load($file->id());
    $this->assertEquals('temporary://missing.txt', $file->getFileUri());
    // Make sure the existing Blob storage file was not touched.
    $this->assertEquals('azure-data', file_get_contents('temporary://missing.txt'));
  }

}
