<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\StreamWrapper;

use AzureOss\Storage\Blob\Exceptions\BlobStorageException;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helfi_azure_fs\BlobStorage;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The azure:// stream wrapper.
 *
 * PHP creates the instances for the stream and file operations itself,
 * without constructor arguments, so the services are fetched from the
 * container.
 *
 * Files are buffered into a temporary stream: reading downloads the whole
 * blob, and writing uploads it when the stream is closed.
 */
final class AzureStreamWrapper implements StreamWrapperInterface {

  /**
   * The stream context.
   *
   * @var resource|null
   */
  public $context;

  /**
   * The URI of the current stream.
   */
  private string $uri = '';

  /**
   * The buffered contents of the opened file.
   *
   * @var resource|null
   */
  private $handle = NULL;

  /**
   * Whether the opened file is written.
   */
  private bool $writable = FALSE;

  /**
   * Whether the opened file has unsaved changes.
   */
  private bool $dirty = FALSE;

  /**
   * The entries of the opened directory.
   *
   * @var string[]
   */
  private array $entries = [];

  /**
   * Gets the path inside the container.
   *
   * @param string|null $uri
   *   The URI, or NULL to use the current one.
   *
   * @return string
   *   The path.
   */
  private function getTarget(?string $uri = NULL): string {
    [, $target] = explode('://', $uri ?? $this->uri, 2) + [1 => ''];
    return BlobStorage::normalize($target);
  }

  /**
   * {@inheritdoc}
   */
  public static function getType(): int {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): TranslatableMarkup {
    return new TranslatableMarkup('Azure Blob Storage');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return new TranslatableMarkup('Files stored in Azure Blob Storage.');
  }

  /**
   * {@inheritdoc}
   */
  public function setUri($uri): void {
    $this->uri = $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getUri(): string {
    return $this->uri;
  }

  /**
   * {@inheritdoc}
   *
   * Existing image style derivatives are served from the blob storage. The
   * missing ones are routed through Drupal, which generates them on the
   * first request. Their URL is the URL of the same path in the public files,
   * like /sites/default/files/styles/[style]/azure/[file], generated with the
   * file URL generator, so the hook_file_url_alter() implementations, like the
   * helfi_proxy's asset path prefix, are applied to it too.
   *
   * @see \Drupal\helfi_azure_fs\Controller\ImageStyleDownloadController
   */
  public function getExternalUrl(): string {
    $target = $this->getTarget();

    if (str_starts_with($target, 'styles/') && !$this->exists($target)) {
      return \Drupal::service('file_url_generator')->generateAbsoluteString('public://' . $target);
    }
    return \Drupal::service(BlobStorage::class)->getPublicUrl($target);
  }

  /**
   * Checks whether the given file exists.
   *
   * @param string $target
   *   The path.
   *
   * @return bool
   *   TRUE if the file exists.
   */
  private function exists(string $target): bool {
    try {
      return \Drupal::service(BlobStorage::class)->stat($target) !== NULL;
    }
    catch (BlobStorageException | GuzzleException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function realpath(): string|false {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL): string {
    $uri ??= $this->uri;
    [$scheme] = explode('://', $uri, 2);
    $dirname = dirname($this->getTarget($uri));

    return $scheme . '://' . ($dirname === '.' ? '' : $dirname);
  }

  /**
   * Triggers a warning, unless errors are suppressed.
   *
   * @param string $message
   *   The message.
   * @param int $options
   *   The stream options.
   */
  private function warn(string $message, int $options = STREAM_REPORT_ERRORS): void {
    if ($options & STREAM_REPORT_ERRORS) {
      trigger_error($message, E_USER_WARNING);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    $this->uri = $path;
    $target = $this->getTarget();
    $mode = str_replace(['b', 't'], '', $mode);
    $this->writable = $mode !== 'r';
    $this->dirty = in_array($mode[0], ['w', 'x', 'c'], TRUE);

    $handle = fopen('php://temp', 'w+b');
    if ($handle === FALSE) {
      return FALSE;
    }
    $this->handle = $handle;

    if ($mode[0] === 'x' && $this->exists($target)) {
      $this->warn("$path already exists", $options);
      return FALSE;
    }

    // Load the existing contents when reading, appending or updating.
    if (in_array($mode[0], ['r', 'a'], TRUE) || $mode === 'c+') {
      try {
        $contents = \Drupal::service(BlobStorage::class)->read($target)->detach();
        if (is_resource($contents)) {
          stream_copy_to_stream($contents, $this->handle);
          fclose($contents);
        }
      }
      catch (BlobStorageException | GuzzleException $e) {
        if ($mode[0] === 'r') {
          $this->warn("Unable to open $path: " . $e->getMessage(), $options);
          return FALSE;
        }
      }
      if ($mode[0] === 'a') {
        fseek($this->handle, 0, SEEK_END);
      }
      else {
        rewind($this->handle);
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count): string|false {
    return fread($this->handle, max(1, (int) $count));
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data): int {
    if (!$this->writable) {
      return 0;
    }
    $this->dirty = TRUE;
    return (int) fwrite($this->handle, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof(): bool {
    return feof($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    return fseek($this->handle, $offset, $whence) === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell(): int|false {
    return ftell($this->handle);
  }

  /**
   * {@inheritdoc}
   *
   * The contents are uploaded when the stream is closed: the upload takes the
   * ownership of the buffer and closes it.
   */
  public function stream_flush(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Uploads the buffered contents.
   */
  public function stream_close(): void {
    if (!is_resource($this->handle)) {
      return;
    }
    $handle = $this->handle;
    $this->handle = NULL;

    if (!$this->dirty) {
      fclose($handle);
      return;
    }
    rewind($handle);

    try {
      $mimeType = \Drupal::service('file.mime_type.guesser')->guessMimeType($this->uri) ?? 'application/octet-stream';
      \Drupal::service(BlobStorage::class)->write($this->getTarget(), $handle, $mimeType);
    }
    catch (BlobStorageException | GuzzleException $e) {
      $this->warn("Unable to write $this->uri: " . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, int>|false
   *   The stat array.
   */
  public function stream_stat(): array|false {
    $stat = fstat($this->handle);
    return $stat === FALSE ? FALSE : $this->buildStat(FALSE, $stat['size'], time());
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation): bool {
    // Blobs can't be locked, but don't make the callers fail.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_metadata($path, $option, $value): bool {
    // Blob storage has no permissions or owners. touch() creates the file.
    if ($option === STREAM_META_TOUCH && !$this->exists($this->getTarget($path))) {
      try {
        \Drupal::service(BlobStorage::class)->write($this->getTarget($path), '', 'application/octet-stream');
      }
      catch (BlobStorageException | GuzzleException) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size): bool {
    $this->dirty = TRUE;
    return ftruncate($this->handle, max(0, (int) $new_size));
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path): bool {
    try {
      \Drupal::service(BlobStorage::class)->delete($this->getTarget($path));
      return TRUE;
    }
    catch (BlobStorageException | GuzzleException $e) {
      $this->warn("Unable to delete $path: " . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Copies the blob on the server side and deletes the source.
   */
  public function rename($path_from, $path_to): bool {
    try {
      \Drupal::service(BlobStorage::class)->copy($this->getTarget($path_from), $this->getTarget($path_to));
      \Drupal::service(BlobStorage::class)->delete($this->getTarget($path_from));
      return TRUE;
    }
    catch (BlobStorageException | GuzzleException $e) {
      $this->warn("Unable to rename $path_from to $path_to: " . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The directories exist implicitly as the prefixes of the blob names, so
   * the created directory only exists for the current request until a file is
   * written in it.
   */
  public function mkdir($path, $mode, $options): bool {
    \Drupal::service(BlobStorage::class)->createDirectory($this->getTarget($path));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * The directories disappear once they don't have any files.
   */
  public function rmdir($path, $options): bool {
    try {
      if (\Drupal::service(BlobStorage::class)->listDirectory($this->getTarget($path))) {
        $this->warn("$path is not empty", $options);
        return FALSE;
      }
      return TRUE;
    }
    catch (BlobStorageException | GuzzleException $e) {
      $this->warn("Unable to remove $path: " . $e->getMessage(), $options);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, int>|false
   *   The stat array, or FALSE if the path doesn't exist.
   */
  public function url_stat($path, $flags): array|false {
    try {
      $stat = \Drupal::service(BlobStorage::class)->stat($this->getTarget($path));
    }
    catch (BlobStorageException | GuzzleException) {
      $stat = NULL;
    }

    if ($stat === NULL) {
      if (!($flags & STREAM_URL_STAT_QUIET)) {
        trigger_error("stat(): stat failed for $path", E_USER_WARNING);
      }
      return FALSE;
    }
    return $this->buildStat($stat['directory'], $stat['size'], $stat['mtime']);
  }

  /**
   * Builds the stat array.
   *
   * @param bool $directory
   *   Whether the path is a directory.
   * @param int $size
   *   The size.
   * @param int $mtime
   *   The modification time.
   *
   * @return array<int|string, int>
   *   The stat array.
   */
  private function buildStat(bool $directory, int $size, int $mtime): array {
    // Everything is readable and writable, since blob storage has no
    // permissions.
    $stat = [
      'dev' => 0,
      'ino' => 0,
      'mode' => $directory ? 0040777 : 0100666,
      'nlink' => 0,
      'uid' => 0,
      'gid' => 0,
      'rdev' => 0,
      'size' => $size,
      'atime' => $mtime,
      'mtime' => $mtime,
      'ctime' => $mtime,
      'blksize' => -1,
      'blocks' => -1,
    ];
    return array_merge(array_values($stat), $stat);
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options): bool {
    try {
      $this->entries = \Drupal::service(BlobStorage::class)->listDirectory($this->getTarget($path));
      return TRUE;
    }
    catch (BlobStorageException | GuzzleException $e) {
      $this->warn("Unable to open $path: " . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir(): string|false {
    $entry = current($this->entries);
    next($this->entries);
    return $entry;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir(): bool {
    reset($this->entries);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir(): bool {
    $this->entries = [];
    return TRUE;
  }

}
