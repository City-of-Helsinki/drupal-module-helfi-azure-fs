<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;
use Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the azure:// stream wrapper when the blob storage is configured.
 */
final class HelfiAzureFsServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   *
   * The stream wrapper is registered when altering, after all the service
   * providers have been registered: the tests define the settings in their own
   * service provider, which is registered after the modules.
   *
   * It also restores the core file system until the Flysystem module is
   * uninstalled. That can be removed once the Flysystem module is uninstalled
   * everywhere.
   *
   * @see helfi_azure_fs_update_90401()
   */
  public function alter(ContainerBuilder $container): void {
    // Flysystem replaces the core file system, which doesn't handle copying
    // files to a directory or the directory names of the root files like the
    // core file system does, and doesn't accept a NULL mode.
    // @todo Remove once we remove the Flysystem dependency from composer.json.
    $fileSystem = $container->getDefinition('file_system');
    if ($fileSystem->getClass() === 'Drupal\\flysystem\\FileSystem\\FlysystemFileSystem') {
      $fileSystem
        ->setClass(FileSystem::class)
        ->setArguments([new Reference('stream_wrapper_manager'), new Reference('settings')]);
    }

    if (BlobStorage::getConfigurationFromSettings(Settings::getInstance()) === NULL) {
      return;
    }
    $container
      ->register('stream_wrapper.helfi_azure_fs', AzureStreamWrapper::class)
      ->addTag('stream_wrapper', ['scheme' => BlobStorage::SCHEME])
      ->setPublic(TRUE);
  }

}
