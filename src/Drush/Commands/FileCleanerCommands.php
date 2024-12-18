<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drush\Attributes\Command;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for cleaning files.
 *
 * Clean files hat were marked permanent because of misconfigured settings.
 * See UHF-10737.
 *
 * @todo delete this. the command is not useful after the files have been cleaned.
 */
class FileCleanerCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a new instance.
   */
  public function __construct(
    private readonly Connection $connection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Transliterates the existing filenames.
   *
   * @see \Drupal\file\FileUsage\DatabaseFileUsageBackend::listUsage()
   *
   * @return int
   *   The exit code.
   */
  #[Command(name: 'helfi:clean:permanent-files')]
  public function clean(array $options = ['no-dry-run' => FALSE]): int {
    $query = $this->connection->select('file_managed', 'f');

    // Left join because file_usage might not exist.
    $query->leftJoin('file_usage', 'fu', 'f.fid = fu.fid AND fu.count > 0');
    $query
      ->fields('f', ['fid'])
      // Permanent only files since temporary files are deleted automatically.
      ->condition('f.status', FileInterface::STATUS_PERMANENT)
      // Filter for rows for which file_usage was not found.
      ->isNull('fu.fid');

    foreach ($query->execute() as $result) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager
        ->getStorage('file')
        ->load($result->fid);

      $url = $file->createFileUrl(FALSE);
      $this->io()->writeln("Marking {$url} for deletion");

      // Temporary files are eventually cleaned by hook_cron.
      if ($options['no-dry-run']) {
        $file->setTemporary();
        $file->save();
      }
    }

    if (!$options['no-dry-run']) {
      $this->io()->warning("Dry run: run with --no-dry-run to actually delete the files");
    }

    return self::EXIT_SUCCESS;
  }

}
