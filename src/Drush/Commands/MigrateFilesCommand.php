<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrates existing local public files to Azure Blob storage.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Migrates existing local public files to Azure Blob storage.',
)]
final class MigrateFilesCommand extends Command {

  use AutowireTrait;
  use AzureStorageSchemeTrait;

  public const string NAME = 'helfi:azure:migrate-files';

  /**
   * Constructs a new instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) : int {
    $io = new DrushStyle($input, $output);

    if (!$scheme = $this->getScheme()) {
      $io->warning('Skipped: use_blob_storage is not enabled.');
      return self::SUCCESS;
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $migrated = $failed = 0;
    $current = 0;

    while (TRUE) {
      $query = $storage->getQuery();
      $query
        ->condition('uri', 'public://%', 'LIKE')
        ->condition('status', FileInterface::STATUS_PERMANENT)
        ->condition('fid', $current, '>')
        ->sort('fid')
        ->range(0, 50);
      $query->accessCheck(FALSE);
      $ids = $query->execute();

      if (!$ids) {
        break;
      }

      foreach ($ids as $id) {
        $current = $id;

        /** @var \Drupal\file\FileInterface|null $file */
        $file = $storage->load($id);

        if (!$file) {
          continue;
        }
        $source = $file->getFileUri();
        $destination = $scheme . '://' . substr($source, strlen('public://'));

        if (!file_exists($source)) {
          $failed++;
          $io->error(sprintf('Skipped migrating %s: file missing on local disk.', $source));
          continue;
        }

        try {
          // Make sure the destination directory exists.
          $dirname = dirname($destination);

          if (!is_dir($dirname)) {
            mkdir($dirname, recursive: TRUE);
          }
          $this->fileSystem->copy($source, $destination, FileExists::Replace);
        }
        catch (FileException $e) {
          $failed++;
          $io->error(sprintf('Failed to copy %s to %s: %s', $source, $destination, $e->getMessage()));
          continue;
        }

        if (!file_exists($destination) || filesize($destination) !== filesize($source)) {
          $failed++;
          $io->error(sprintf('Verification failed for %s, keeping local copy.', $source));
          continue;
        }

        $file->setFileUri($destination);
        $file->save();

        $migrated++;
      }
    }

    $io->success(sprintf('Migrated %d file(s) to Azure Blob storage, %d failed (see above).', $migrated, $failed));

    return self::SUCCESS;
  }

}
