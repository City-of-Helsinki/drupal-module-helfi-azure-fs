<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\Site\Settings;
use Drupal\helfi_azure_fs\BlobStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the blob storage without connecting to it.
 */
#[CoversClass(BlobStorage::class)]
#[Group('helfi_azure_fs')]
class BlobStorageTest extends UnitTestCase {

  /**
   * Tests the configuration lookup.
   *
   * @param array<string, mixed> $settings
   *   The settings.
   * @param array<string, string>|null $expected
   *   The expected configuration.
   */
  #[DataProvider('configurationData')]
  public function testConfiguration(array $settings, ?array $expected) : void {
    $settings = new Settings($settings);

    $this->assertSame($expected, BlobStorage::getConfigurationFromSettings($settings));
    $this->assertSame($expected !== NULL, (new BlobStorage($settings))->isConfigured());
  }

  /**
   * The data provider for testConfiguration().
   *
   * @return array<string, array{array<string, mixed>, array<string, string>|null}>
   *   The data.
   */
  public static function configurationData() : array {
    $defaults = ['endpointSuffix' => 'core.windows.net', 'protocol' => 'https'];

    return [
      'not configured' => [[], NULL],
      'settings' => [
        ['helfi_azure_fs' => ['name' => 'name', 'container' => 'container', 'token' => 'token']],
        ['name' => 'name', 'container' => 'container', 'token' => 'token'] + $defaults,
      ],
      'settings with the defaults overridden' => [
        ['helfi_azure_fs' => ['container' => 'container', 'endpointSuffix' => 'example.com', 'protocol' => 'http']],
        ['container' => 'container', 'endpointSuffix' => 'example.com', 'protocol' => 'http'],
      ],
      'Flysystem settings are not used' => [
        [
          'flysystem' => [
            'azure' => ['driver' => 'helfi_azure', 'config' => ['name' => 'name', 'container' => 'container']],
          ],
        ],
        NULL,
      ],
    ];
  }

  /**
   * Tests the path normalization.
   */
  #[DataProvider('normalizeData')]
  public function testNormalize(string $path, string $expected) : void {
    $this->assertSame($expected, BlobStorage::normalize($path));
  }

  /**
   * The data provider for testNormalize().
   *
   * @return array<int, array{string, string}>
   *   The data.
   */
  public static function normalizeData() : array {
    return [
      ['', ''],
      ['/', ''],
      ['file.txt', 'file.txt'],
      ['/a//b.txt/', 'a/b.txt'],
      ['styles//thumbnail///azure/image.jpg', 'styles/thumbnail/azure/image.jpg'],
      ['a/', 'a'],
    ];
  }

  /**
   * Tests the public URLs.
   *
   * @param array<string, string> $configuration
   *   The configuration.
   * @param string $expected
   *   The expected URL of 'folder/file name #1.jpg'.
   */
  #[DataProvider('publicUrlData')]
  public function testPublicUrl(array $configuration, string $expected) : void {
    $storage = new BlobStorage(new Settings(['helfi_azure_fs' => $configuration]));

    $this->assertSame($expected, $storage->getPublicUrl('folder/file name #1.jpg'));
    $this->assertSame($expected, $storage->getPublicUrl('/folder//file name #1.jpg'));
  }

  /**
   * The data provider for testPublicUrl().
   *
   * @return array<string, array{array<string, string>, string}>
   *   The data.
   */
  public static function publicUrlData() : array {
    $path = 'folder/file%20name%20%231.jpg';

    return [
      'account' => [
        ['name' => 'account', 'container' => 'container', 'token' => 'token'],
        "https://account.blob.core.windows.net/container/$path",
      ],
      'public URL base' => [
        ['name' => 'account', 'container' => 'container', 'public_url_base' => 'https://cdn.example.com/files/'],
        "https://cdn.example.com/files/$path",
      ],
      'account key connection string' => [
        [
          'connectionString' => 'DefaultEndpointsProtocol=http;AccountName=account;AccountKey=a2V5==;EndpointSuffix=example.com',
          'container' => 'container',
        ],
        "http://account.blob.example.com/container/$path",
      ],
      'SAS connection string' => [
        [
          'connectionString' => 'BlobEndpoint=https://account.blob.core.windows.net/;SharedAccessSignature=sv=1&sig=2',
          'container' => 'container',
        ],
        "https://account.blob.core.windows.net/container/$path",
      ],
    ];
  }

  /**
   * Tests that a missing container is reported.
   */
  public function testMissingContainer() : void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "container" is missing');
    (new BlobStorage(new Settings(['helfi_azure_fs' => ['name' => 'account']])))->getPublicUrl('file.txt');
  }

}
