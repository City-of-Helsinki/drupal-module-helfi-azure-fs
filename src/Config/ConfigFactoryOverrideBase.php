<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Config;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageInterface;

/**
 * A base class for Config overrides.
 */
abstract class ConfigFactoryOverrideBase implements ConfigFactoryOverrideInterface {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Checks whether Azure blob storage is enabled.
   *
   * @return bool
   *   TRUE if Azure blob storage should be used.
   */
  protected function useBlobStorage(): bool {
    return (bool) $this->configFactory->get('helfi_azure_fs.settings')->get('use_blob_storage');
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION): ?StorableConfigBase {
    return NULL;
  }

}
