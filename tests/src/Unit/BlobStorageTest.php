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
    return [
      'not configured' => [[], NULL],
      'settings' => [
        ['helfi_azure_fs' => ['name' => 'name', 'container' => 'container', 'token' => 'token']],
        ['name' => 'name', 'container' => 'container', 'token' => 'token'],
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
   */
  #[DataProvider('publicUrlData')]
  public function testPublicUrl(string $publicUrlBase, string $path, string $expected) : void {
    $storage = new BlobStorage(new Settings([
      'helfi_azure_fs' => ['container' => 'container', 'public_url_base' => $publicUrlBase],
    ]));
    $this->assertSame($expected, $storage->getPublicUrl($path));
  }

  /**
   * The data provider for testPublicUrl().
   *
   * @return array<string, array{string, string, string}>
   *   The data.
   */
  public static function publicUrlData() : array {
    $base = 'https://account.blob.core.windows.net/container';

    return [
      'file' => [$base, 'file.txt', "$base/file.txt"],
      'encoded' => [$base, 'folder/file name #1.jpg', "$base/folder/file%20name%20%231.jpg"],
      'normalized' => [$base, '/folder//file.txt', "$base/folder/file.txt"],
      'trailing slash' => ["$base/", 'file.txt', "$base/file.txt"],
      'CDN' => ['https://cdn.example.com/files', 'file.txt', 'https://cdn.example.com/files/file.txt'],
    ];
  }

  /**
   * Tests that the missing required settings are reported.
   */
  #[DataProvider('missingSettingData')]
  public function testMissingSetting(string $key) : void {
    $configuration = ['container' => 'container', 'public_url_base' => 'https://cdn.example.com'];
    unset($configuration[$key]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(sprintf('The "%s" is missing', $key));
    (new BlobStorage(new Settings(['helfi_azure_fs' => $configuration])))->getPublicUrl('file.txt');
  }

  /**
   * The data provider for testMissingSetting().
   *
   * @return array<int, array{string}>
   *   The data.
   */
  public static function missingSettingData() : array {
    return [['container'], ['public_url_base']];
  }

}
