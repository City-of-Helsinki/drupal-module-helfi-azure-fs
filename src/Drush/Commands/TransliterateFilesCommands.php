<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Drush\Commands;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\File\Event\FileUploadSanitizeNameEvent;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\Entity\File;
use Drush\Attributes\Command;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
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
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Gets the sanitized filename.
   *
   * @param string $filename
   *   The file to sanitize.
   *
   * @return string
   *   The sanitized filename.
   */
  private function getSanitizedFilename(string $filename): string {
    $event = new FileUploadSanitizeNameEvent($filename, '');
    $this->eventDispatcher->dispatch($event);

    return $event->getFilename();
  }

  /**
   * Processes all fields for given entity type.
   *
   * @param string $entityType
   *   The entity type to process.
   * @param array $fields
   *   The fields to process.
   */
  private function processEntityType(string $entityType, array $fields) : void {
    foreach ($fields as $name => $field) {
      $query = $this->entityTypeManager
        ->getStorage($entityType)
        ->getQuery();
      // Only load entities that has link to a local or MS blob
      // storage file.
      $conditionGroup = $query->orConditionGroup();
      $conditionGroup
        ->condition($name, '%blob.core.windows.net%', 'LIKE');
      $conditionGroup
        ->condition($name, '/sites/default/files%', 'LIKE');
      $query->exists($name)
        ->condition($conditionGroup);
      $query->accessCheck(FALSE);
      $ids = $query->execute();

      foreach ($ids as $id) {
        $entity = $this->entityTypeManager->getStorage($entityType)
          ->load($id);

        assert($entity instanceof TranslatableInterface);
        foreach ($entity->getTranslationLanguages() as $language) {
          $this->processFieldLinks($entity->getTranslation($language->getId()), $name);
        }
      }
    }
  }

  /**
   * Checks if the given link is valid.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   TRUE if link is valid, FALSE if not.
   */
  private function isValidLink(string $url) : bool {
    $validLinks = [
      'blob.core.windows.net',
      '/sites/default/files/',
    ];

    return (bool) array_filter($validLinks, fn ($link) => str_contains($url, $link));
  }

  /**
   * Checks if the given remote file exists.
   *
   * @param string $url
   *   The url to check.
   *
   * @return bool
   *   TRUE if remote file exists, FALSE if not.
   */
  private function remoteFileExists(string $url) : bool {
    try {
      $this->httpClient->request('HEAD', $url, ['timeout' => 15]);

      return TRUE;
    }
    catch (GuzzleException) {
    }
    return FALSE;
  }

  /**
   * Sanitize filenames inside text fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity translation to process.
   * @param string $fieldName
   *   The field name.
   */
  private function processFieldLinks(ContentEntityInterface $entity, string $fieldName) : void {
    if (!$value = $entity->get($fieldName)->value) {
      return;
    }

    $hasChanges = FALSE;
    $dom = Html::load($value);
    /** @var \DOMElement $node */
    foreach ($dom->getElementsByTagName('a') as $node) {
      // Nothing to do if link has no href.
      if (!$href = $node->getAttribute('href')) {
        continue;
      }
      $href = trim($href);

      // Skip invalid links or links that does not result in 404 error.
      if (!$this->isValidLink($href) || $this->remoteFileExists($href)) {
        continue;
      }
      $this->io()->note(sprintf('Found a broken link "%s"', $href));
      $basename = basename($href);

      // Test sanitized filename and urldecoded+sanitized filename.
      $candidates = [
        $this->getSanitizedFilename($basename),
        $this->getSanitizedFilename(urldecode($basename)),
      ];

      $newUrl = NULL;
      foreach ($candidates as $candidate) {
        $sanitizedUrl = str_replace($basename, $candidate, $href);

        if (!$this->remoteFileExists($sanitizedUrl)) {
          continue;
        }
        $newUrl = $sanitizedUrl;
      }

      if (!$newUrl) {
        $this->io()->warning(sprintf('Failed to process [entity id: %s, entity type: %s]: "%s"', $entity->id(), $entity->getEntityTypeId(), $href));

        continue;
      }
      $hasChanges = TRUE;
      $value = str_replace($href, $newUrl, $value);
    }

    if ($hasChanges) {
      $entity->set($fieldName, $value);
      $entity->save();
    }
  }

  /**
   * Transliterates all files embedded in text fields.
   *
   * @return int
   *   The exit code.
   */
  #[Command(name: 'helfi:transliterate:fields')]
  public function transliterateTextFields() : int {
    $fieldTypes = [
      'text_with_summary',
      'text',
      'text_long',
    ];
    foreach ($fieldTypes as $fieldType) {
      $fieldMap = $this->entityFieldManager->getFieldMapByFieldType($fieldType);

      foreach ($fieldMap as $entityType => $fields) {
        $this->processEntityType($entityType, $fields);
      }
    }
    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Transliterates the existing filenames.
   *
   * @return int
   *   The exit code.
   */
  #[Command(name: 'helfi:transliterate:files')]
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

      $sanitizedFilename = $this->getSanitizedFilename($file->getFilename());

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
