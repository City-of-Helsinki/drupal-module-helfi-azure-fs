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
 * Repairs file entities whose Azure blob is missing.
 *
 * Files that already have an 'azure://' URI but whose blob does not
 * actually exist there, e.g. due to an interrupted upload, are
 * re-uploaded from their local 'public://' counterpart, if one still
 * exists on disk.
 */
#[AsCommand(
  name: self::NAME,
  description: 'Repairs file entities whose Azure blob is missing.',
)]
final class RepairFilesCommand extends Command {

  use AutowireTrait;
  use AzureStorageSchemeTrait;

  public const string NAME = 'helfi:azure:repair-files';

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
    $repaired = $missing = $failed = 0;
    $current = 0;

    while (TRUE) {
      $query = $storage->getQuery();
      $query
        ->condition('uri', $scheme . '://%', 'LIKE')
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
        $destination = $file->getFileUri();

        if (file_exists($destination)) {
          // Already present on Azure, nothing to repair.
          continue;
        }
        $source = 'public://' . substr($destination, strlen($scheme . '://'));

        if (!file_exists($source)) {
          $missing++;
          $io->error(sprintf('%s is missing on Azure and no local copy exists at %s.', $destination, $source));
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
          $io->error(sprintf('Verification failed for %s after copying from %s.', $destination, $source));
          continue;
        }

        $repaired++;
      }
    }

    $io->success(sprintf('Checked all files: repaired %d, %d missing on both Azure and local disk, %d failed to copy (see above).', $repaired, $missing, $failed));

    return self::SUCCESS;
  }

}
