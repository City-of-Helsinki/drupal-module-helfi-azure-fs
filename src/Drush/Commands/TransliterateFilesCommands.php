<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drush\Attributes\Command;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A drush command to transliterate existing file names.
 *
 * Usage:
 *
 * $ drush helfi:files:transliterate
 *    This will transliterate all existing files to match the current
 *    transliterate settings.
 */
final class TransliterateFilesCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a new instance.
   */
  public function __construct(
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    $this->io = new SymfonyStyle($this->input(), $this->output());
    parent::__construct();
  }

  /**
   * Gets the sanitized filename.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to sanitize.
   *
   * @return string
   *   The sanitized filename.
   */
  private function getSanitizedFilename(FileInterface $file): string {
    $event = new FileUploadSanitizeNameEvent($file->getFilename(), '');
    $this->eventDispatcher->dispatch($event);

    return $event->getFilename();
  }

  /**
   * Transliterates the existing filenames.
   *
   * @return int
   *   The exit code.
   */
  #[Command(name: 'helfi:files:transliterate')]
  public function transliterate() : int {
    $ids = $this->entityTypeManager
      ->getStorage('file')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    foreach ($ids as $id) {
      if (!$file = File::load($id)) {
        continue;
      }

      $sanitizedFilename = $this->getSanitizedFilename($file);

      if ($sanitizedFilename === $file->getFilename()) {
        continue;
      }
      $originalFileUri = $file->getFileUri();

      if (!file_exists($originalFileUri)) {
        $this->io()->warning("File {$originalFileUri} does not exist on disk. Skipping ...");

        continue;
      }
      $directory = $this->fileSystem->dirname($originalFileUri);
      $sanitizedUri = sprintf('%s/%s', $directory, $sanitizedFilename);
      $sanitizedUri = $this->streamWrapperManager->normalizeUri($sanitizedUri);

      try {
        $this->fileSystem->move($originalFileUri, $sanitizedUri);
      }
      catch (FileException $e) {
        $this->io()->error($e->getMessage());

        continue;
      }

      // Make sure file was actually renamed.
      if (!file_exists($sanitizedUri)) {
        continue;
      }
      $file->setFileUri($sanitizedUri);
      $file->setFilename($sanitizedFilename);
      $file->save();

      $this->io()->success("File '{$originalFileUri}' has been renamed to '{$sanitizedUri}'.");
    }
    return DrushCommands::EXIT_SUCCESS;
  }

}
