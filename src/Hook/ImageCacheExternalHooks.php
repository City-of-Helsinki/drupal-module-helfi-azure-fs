<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Provides hooks for 'imagecache_external' module.
 */
final class ImageCacheExternalHooks {

  use AutowireTrait;

  public function __construct(
    #[AutowireServiceClosure(service: ConfigFactoryInterface::class)] private readonly \Closure $configFactoryClosure,
  ) {
  }

  /**
   * Overrides the file scheme for 'imagecache_external' images.
   *
   * @param array<mixed> $alter
   *   The data to alter.
   * @param array<mixed> $context
   *   The context.
   */
  #[Hook('imagecache_external_destination_alter')]
  public function alterDestination(array &$alter, array $context): void {
    $config = ($this->configFactoryClosure)()
      ->get('helfi_azure_fs.settings');

    if (!$config->get('use_blob_storage')) {
      return;
    }
    // Override the file scheme. This will cause all external images
    // to be stored in configured Azure blob storage.
    $alter['scheme'] = 'azure';
  }

}
