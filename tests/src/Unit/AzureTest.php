<?php

declare(strict_types = 1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\helfi_azure_fs\Flysystem\Azure;
use Drupal\Tests\UnitTestCase;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedAccessSignatureAuthScheme;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedKeyAuthScheme;

/**
 * @coversDefaultClass \Drupal\helfi_azure_fs\Flysystem\Azure
 *
 * @group helfi_azure_fs
 */
class AzureTest extends UnitTestCase {

  /**
   * Tests connection string.
   *
   * @covers ::getClient
   * @covers ::getBlobUri
   * @dataProvider connectionStringData
   */
  public function testGetClient(array $configuration, array $expected) : void {
    $azure = new Azure($configuration);
    $client = $azure->getClient();
    $this->assertEquals($expected['primaryUri'], (string) $client->getPsrPrimaryUri());
    $this->assertEquals($expected['secondaryUri'], (string) $client->getPsrSecondaryUri());
    $middleware = $client->getMiddlewares()[0];
    $reflectionClass = new \ReflectionClass($middleware);
    $scheme = $reflectionClass
      ->getProperty('authenticationScheme');
    $scheme->setAccessible(TRUE);

    $this->assertInstanceOf($expected['authenticationScheme'], $scheme->getValue($middleware));
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
        [
          'primaryUri' => 'https://test.blob.core.windows.net/',
          'secondaryUri' => 'https://test-secondary.blob.core.windows.net/',
          'authenticationScheme' => SharedKeyAuthScheme::class,
        ],
      ],
      // Test with SAS token.
      [
        [
          'protocol' => 'https',
          'name' => 'test',
          'endpointSuffix' => 'core.windows.net',
          'token' => '321',
        ],
        [
          'primaryUri' => 'https://test.blob.core.windows.net/',
          'secondaryUri' => 'https://test-secondary.blob.core.windows.net/',
          'authenticationScheme' => SharedAccessSignatureAuthScheme::class,
        ],
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
        [
          'primaryUri' => 'https://test.blob.core.windows.net/',
          'secondaryUri' => 'https://test-secondary.blob.core.windows.net/',
          'authenticationScheme' => SharedAccessSignatureAuthScheme::class,
        ],
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
        [
          'primaryUri' => 'https://test.blob.core.windows.net/',
          'secondaryUri' => 'https://test-secondary.blob.core.windows.net/',
          'authenticationScheme' => SharedKeyAuthScheme::class,
        ],
      ],
    ];
  }

}
