<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\Site\Settings;
use Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Uses the Azure stream wrapper for the 'helfi_azure' Flysystem schemes.
 *
 * @see \Drupal\flysystem\FlysystemServiceProvider::alter()
 */
final class HelfiAzureFsServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('flysystem.filesystem_factory')) {
      return;
    }

    foreach (Settings::get('flysystem', []) as $scheme => $settings) {
      if (($settings['driver'] ?? NULL) !== 'helfi_azure') {
        continue;
      }
      $serviceId = 'stream_wrapper.flysystem.' . $scheme;

      // Flysystem skips the schemes that already have a stream wrapper
      // service, so this works whether it's altered before or after us.
      if ($container->hasDefinition($serviceId)) {
        $container->getDefinition($serviceId)->setClass(AzureStreamWrapper::class);
        continue;
      }
      $container->setDefinition($serviceId, new Definition(AzureStreamWrapper::class)
        ->addTag('stream_wrapper', ['scheme' => $scheme])
        ->addMethodCall('setFactory', [new Reference('flysystem.filesystem_factory')])
        ->setPublic(TRUE));
    }
  }

}
