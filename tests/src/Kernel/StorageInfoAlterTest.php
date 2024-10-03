<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\field\Kernel\FieldKernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests that file field's uri_scheme can be altered.
 *
 * @group helfi_azure_fs
 */
class StorageInfoAlterTest extends FieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'image',
    'file',
    'flysystem',
    'flysystem_azure',
    'helfi_azure_fs',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Register azure stream wrapper manually.
    $container
      ->register('flysystem_stream_wrapper.azure', 'Drupal\flysystem\FlysystemBridge')
      ->addTag('stream_wrapper', ['scheme' => 'azure']);
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() : void {
    parent::setUp();
    $this->installEntitySchema('file');

    foreach (['file', 'image'] as $type) {
      FieldStorageConfig::create([
        'field_name' => 'field_' . $type,
        'entity_type' => 'entity_test',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'entity_type' => 'entity_test',
        'field_name' => 'field_' . $type,
        'bundle' => 'entity_test',
      ])->save();

      // Create a form display for the default form mode.
      \Drupal::service('entity_display.repository')
        ->getFormDisplay('entity_test', 'entity_test')
        ->save();
    }
  }

  /**
   * Asserts field's uri_scheme value.
   *
   * @param string $expected_scheme
   *   The expected scheme.
   */
  private function assertScheme(string $expected_scheme) : void {
    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $manager */
    $manager = $this->container->get('entity_field.manager');
    $storage = $manager->getFieldStorageDefinitions('entity_test');

    foreach (['file', 'image'] as $type) {
      $data = $storage['field_' . $type];
      $this->assertEquals($expected_scheme, $data->getSetting('uri_scheme'));
    }

  }

  /**
   * Make sure we can alter storage uri_scheme.
   */
  public function testAlter() : void {
    $this->assertScheme('public');

    $this->config('helfi_azure_fs.settings')
      ->set('storage_scheme', 'azure')
      ->set('use_blob_storage', TRUE)->save();
    drupal_flush_all_caches();

    $this->assertScheme('azure');

  }

}
