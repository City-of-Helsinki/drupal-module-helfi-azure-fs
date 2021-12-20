<?php

declare(strict_types = 1);

namespace Drupal\helfi_azure_fs\Flysystem;

use Drupal\flysystem_azure\Flysystem\Azure as AzureBase;

/**
 * Drupal plugin for the "Azure" Flysystem adapter.
 *
 * Contains helfi specific fixes.
 *
 * @Adapter(id = "helfi_azure")
 */
final class Azure extends AzureBase {
}
