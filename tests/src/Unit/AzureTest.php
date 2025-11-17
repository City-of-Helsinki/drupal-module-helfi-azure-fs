<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;
use Drupal\Tests\UnitTestCase;
use Drupal\helfi_azure_fs\Flysystem\Azure;
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
    $loggerFactory = new LoggerChannelFactory(
      $this->prophesize(RequestStack::class)->reveal(),
      $this->prophesize(AccountInterface::class)->reveal(),
    );
    $loggerFactory->addLogger($this->prophesize(LoggerInterface::class)->reveal());
    $fileUrlGenerator = $this->prophesize(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->generateString(Argument::any());
    $url_generator = $this->prophesize(UrlGeneratorInterface::class);
    $url_generator
      ->generateFromRoute(
        'flysystem.serve',
        Argument::any(),
        ['absolute' => TRUE],
        FALSE
      )
      ->shouldBeCalledTimes(1)
      ->will(function ($args) {
        return 'flysystem.serve: ' . $args[1]['filepath'];
      });

    $configuration = [
      'protocol' => 'https',
      'name' => 'test',
      'endpointSuffix' => 'core.windows.net',
      'container' => 'test',
    ];

    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    $container->set('file_url_generator', $fileUrlGenerator->reveal());
    $container->set('url_generator', $url_generator->reveal());

    // Required by Url::fromRoute.
    \Drupal::setContainer($container);

    $azure = Azure::create($container, $configuration, 'helfi_azure', []);
    // Make sure non-image style URLs are served directly from blob storage.
    $this->assertEquals('https://test.blob.core.windows.net/test/test.jpg', $azure->getExternalUrl('vfs://test.jpg'));
    // Make sure image style URL is passed to file url generator service.
    $this->assertEquals('flysystem.serve: styles/test.jpg', $azure->getExternalUrl('vfs://styles/test.jpg'));
    // Test static cache, as generateFromRoute should not be called 2nd time
    // and is restricted above to 1 call.
    $this->assertEquals('flysystem.serve: styles/test.jpg', $azure->getExternalUrl('vfs://styles/test.jpg'));

    // Check that file uri is encoded.
    $this->assertEquals('https://test.blob.core.windows.net/test/test%29.jpg', $azure->getExternalUrl('vfs://test).jpg'));
  }

  /**
   * Tests getAdapter connection string.
   *
   * @dataProvider connectionStringData
   */
  public function testGetAdapter(array $configuration, string $expected) : void {
    $configuration += ['container' => 'test'];

    $logger = $this->prophesize(LoggerInterface::class);
    $azure = new Azure($configuration, $logger->reveal());
    $this->assertEquals($expected, $azure->getConnectionString());
    $this->assertInstanceOf(AzureBlobStorageAdapter::class, $azure->getAdapter());
  }

  /**
   * The data provider for testConnectionString().
   *
   * @return array
   *   The data.
   */
  public function connectionStringData() : array {
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

}
