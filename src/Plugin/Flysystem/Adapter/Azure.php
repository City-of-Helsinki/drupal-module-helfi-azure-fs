<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Plugin\Flysystem\Adapter;

use AzureOss\Storage\Blob\BlobServiceClient;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\AdapterDriverPluginBase;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Exception\AdapterConfigurationException;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;

/**
 * Flysystem adapter driver for Azure Blob Storage.
 *
 * Configure it in settings.php, for example:
 *
 * @code
 * $settings['flysystem']['azure'] = [
 *   'driver' => 'helfi_azure',
 *   // Required by Flysystem 3 to generate file URLs.
 *   'public_url_base' => 'https://[name].blob.core.windows.net/[container]',
 *   'config' => [
 *     'name' => '[name]',
 *     'container' => '[container]',
 *     'key' => '[key]',
 *     'endpointSuffix' => 'core.windows.net',
 *     'protocol' => 'https',
 *   ],
 * ];
 * @endcode
 */
#[FlysystemAdapter(
  id: 'helfi_azure',
  label: new TranslatableMarkup('Azure Blob Storage'),
)]
final class Azure extends AdapterDriverPluginBase {

  /**
   * The adapters, keyed by a hash of the configuration.
   *
   * The plugin is instantiated every time the driver is needed. Sharing the
   * adapter lets the stream wrapper reuse the connection of the filesystem.
   *
   * @var array<string, \Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter>
   *
   * @see \Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper::url_stat()
   */
  protected static array $adapters = [];

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $config
   *   The driver configuration.
   */
  public function buildAdapter(array $config): AzureBlobStorageAdapter {
    if (empty($config['container'])) {
      throw new AdapterConfigurationException('The "container" setting is required.');
    }
    $key = hash('xxh3', serialize($config));

    if (!isset(self::$adapters[$key])) {
      $client = BlobServiceClient::fromConnectionString($this->getConnectionString($config))
        ->getContainerClient($config['container']);

      self::$adapters[$key] = new AzureBlobStorageAdapter($client);
    }
    return self::$adapters[$key];
  }

  /**
   * Gets the connection string.
   *
   * @param array<string, mixed> $config
   *   The driver configuration.
   *
   * @return string
   *   The connection string.
   */
  public function getConnectionString(array $config): string {
    if (!empty($config['connectionString'])) {
      return $config['connectionString'];
    }

    foreach (['name', 'protocol', 'endpointSuffix'] as $key) {
      if (empty($config[$key])) {
        throw new AdapterConfigurationException(sprintf('The "%s" setting is required when "connectionString" is not set.', $key));
      }
    }

    if (!empty($config['token'])) {
      $values = [
        'BlobEndpoint' => vsprintf('%s://%s.blob.%s', [
          $config['protocol'],
          $config['name'],
          $config['endpointSuffix'],
        ]),
        'SharedAccessSignature' => $config['token'],
      ];
    }
    else {
      $values = [
        'DefaultEndpointsProtocol' => $config['protocol'],
        'AccountName' => $config['name'],
        'EndpointSuffix' => $config['endpointSuffix'],
        'AccountKey' => $config['key'] ?? '',
      ];
    }
    $connectionString = '';
    foreach ($values as $key => $value) {
      $connectionString .= sprintf('%s=%s;', $key, $value);
    }
    return $connectionString;
  }

  /**
   * {@inheritdoc}
   *
   * The driver is configured in settings.php only, so the form has no fields.
   *
   * @param array<string, mixed> $form
   *   The driver configuration fieldset.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $config
   *   The current driver configuration.
   *
   * @return array<string, mixed>
   *   The fieldset.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array $config): array {
    $form['settings_php'] = [
      '#markup' => $this->t("This driver is configured in settings.php, using <code>\$settings['flysystem']</code>."),
    ];
    return $form;
  }

}
