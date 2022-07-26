<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Flysystem\Azure;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\helfi_azure_fs\Flysystem\Azure
 *
 * @group helfi_azure_fs
 */
class AzureTest extends UnitTestCase {

  /**
   * @covers ::getConnectionString
   * @dataProvider connectionStringData
   */
  public function testConnectionString(array $configuration, string $expected) : void {
    $azure = new Azure($configuration);
    $this->assertEquals($expected, $azure->getConnectionString());
  }

  /**
   * The data provider for testConnectionString().
   *
   * @return array
   *   The data.
   */
  public function connectionStringData() : array {
    return [
      // Test with regular account key.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'key' => '123',
        ],
        'DefaultEndpointsProtocol=https;AccountName=test;EndpointSuffix=core.windows.net;AccountKey=123',
      ],
      // Test with SAS token.
      [
        [
          'protocol' => 'https',
          'name' => 'sastest',
          'endpointSuffix' => 'core.windows.net',
          'token' => '321',
        ],
        'DefaultEndpointsProtocol=https;AccountName=sastest;EndpointSuffix=core.windows.net;SharedAccessSignature=321',
      ],
      // Test with SAS token and account key.
      [
        [
          'protocol' => 'https',
          'name' => 'sastest',
          'endpointSuffix' => 'core.windows.net',
          'key' => '123',
          'token' => '321',
        ],
        'DefaultEndpointsProtocol=https;AccountName=sastest;EndpointSuffix=core.windows.net;SharedAccessSignature=321',
      ],
    ];
  }

}
