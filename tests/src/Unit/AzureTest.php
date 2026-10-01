<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\flysystem\Exception\AdapterConfigurationException;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;
use Drupal\helfi_azure_fs\Plugin\Flysystem\Adapter\Azure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Azure adapter driver.
 */
#[Group('helfi_azure_fs')]
class AzureTest extends UnitTestCase {

  /**
   * Gets the driver.
   */
  private function getSut(): Azure {
    return new Azure([], 'helfi_azure', []);
  }

  /**
   * Tests the connection string and the built adapter.
   *
   * @param array<string, string> $configuration
   *   The driver configuration.
   * @param string $expected
   *   The expected connection string.
   */
  #[DataProvider('connectionStringData')]
  public function testBuildAdapter(array $configuration, string $expected) : void {
    $configuration += ['container' => 'test'];

    $sut = $this->getSut();
    $this->assertEquals($expected, $sut->getConnectionString($configuration));
    $this->assertInstanceOf(AzureBlobStorageAdapter::class, $sut->buildAdapter($configuration));
  }

  /**
   * The data provider for testBuildAdapter().
   *
   * @return array<int, array{array<string, string>, string}>
   *   The data.
   */
  public static function connectionStringData() : array {
    return [
      // Test with connection string.
      [
        [
          'connectionString' => 'DefaultEndpointsProtocol=https;AccountName=test;EndpointSuffix=core.windows.net;AccountKey=123;',
        ],
        'DefaultEndpointsProtocol=https;AccountName=test;EndpointSuffix=core.windows.net;AccountKey=123;',
      ],
      // Test with regular account key.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'key' => '123',
        ],
        'DefaultEndpointsProtocol=https;AccountName=test;EndpointSuffix=core.windows.net;AccountKey=123;',
      ],
      // Test with SAS token.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'token' => '321',
        ],
        'BlobEndpoint=https://test.blob.core.windows.net;SharedAccessSignature=321;',
      ],
      // Make sure connection string prefers SAS token when both the key and
      // token is set.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'key' => '123',
          'token' => '321',
        ],
        'BlobEndpoint=https://test.blob.core.windows.net;SharedAccessSignature=321;',
      ],
      // Make sure connection string fallbacks to key connection when SAS
      // token is empty.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'key' => '123',
          'token' => '',
        ],
        'DefaultEndpointsProtocol=https;AccountName=test;EndpointSuffix=core.windows.net;AccountKey=123;',
      ],
    ];
  }

  /**
   * Tests that a missing container is reported.
   */
  public function testMissingContainer() : void {
    $this->expectException(AdapterConfigurationException::class);
    $this->expectExceptionMessage('The "container" setting is required.');
    $this->getSut()->buildAdapter(['connectionString' => 'UseDevelopmentStorage=true']);
  }

  /**
   * Tests that a missing account name is reported.
   */
  public function testMissingAccountName() : void {
    $this->expectException(AdapterConfigurationException::class);
    $this->expectExceptionMessage('The "name" setting is required when "connectionString" is not set.');
    $this->getSut()->buildAdapter([
      'container' => 'test',
      'protocol' => 'https',
      'endpointSuffix' => 'core.windows.net',
      'key' => '123',
    ]);
  }

}
