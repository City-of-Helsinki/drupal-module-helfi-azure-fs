<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\helfi_azure_fs\Flysystem\Azure;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedAccessSignatureAuthScheme;
use MicrosoftAzure\Storage\Common\Internal\Authentication\SharedKeyAuthScheme;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use org\bovigo\vfs\vfsStream;

/**
 * Tests Azure.
 *
 * @group helfi_azure_fs
 */
class AzureTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Tests getExternalUrl.
   */
  public function testGetExternalUrl() : void {
    vfsStream::setup('flysystem');
    $fileUrlGenerator = $this->prophesize(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->generateString(Argument::any())
      ->shouldBeCalledTimes(2)
      ->willReturn(
        '/styles/test.jpg',
        '/styles/test).jpg',
      );
    $loggerFactory = new LoggerChannelFactory(
      $this->prophesize(RequestStack::class)->reveal(),
      $this->prophesize(AccountInterface::class)->reveal(),
    );
    $loggerFactory->addLogger($this->prophesize(LoggerInterface::class)->reveal());

    $configuration = [
      'protocol' => 'https',
      'name' => 'test',
      'endpointSuffix' => 'core.windows.net',
      'container' => 'test',
    ];

    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    $container->set('file_url_generator', $fileUrlGenerator->reveal());
    $azure = Azure::create($container, $configuration, 'helfi_azure', []);
    // Make sure non-image style URLs are served directly from blob storage.
    $this->assertEquals('https://test.blob.core.windows.net/test/test.jpg', $azure->getExternalUrl('vfs://test.jpg'));
    // Make sure image style URL is passed to file url generator service.
    $this->assertEquals('/styles/test.jpg', $azure->getExternalUrl('vfs://styles/test.jpg'));

    // Check that file uri is encoded.
    $this->assertEquals('https://test.blob.core.windows.net/test/test%29.jpg', $azure->getExternalUrl('vfs://test).jpg'));
    $this->assertEquals('/styles/test%29.jpg', $azure->getExternalUrl('vfs://styles/test).jpg'));

    // Test static cache, as generateString should not be called 3rd time
    // and is restricted above to 2 calls.
    $this->assertEquals('/styles/test.jpg', $azure->getExternalUrl('vfs://styles/test.jpg'));
  }

  /**
   * Tests connection string.
   *
   * @dataProvider connectionStringData
   */
  public function testGetClient(array $configuration, array $expected) : void {
    $fileUrlGenerator = $this->prophesize(FileUrlGeneratorInterface::class);
    $logger = $this->prophesize(LoggerInterface::class);
    $azure = new Azure($configuration, $logger->reveal(), $fileUrlGenerator->reveal());
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
