<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\flysystem\FlysystemFactory;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use Drupal\Tests\TestFileCreationTrait;
use League\Flysystem\AdapterInterface;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tests Azure adapter plugin.
 *
 * @group helfi_azure_fs
 */
class FlysystemRoutesTest extends KernelTestBase {

  use ProphecyTrait;
  use ApiTestTrait;
  use FlysystemUrlTrait;
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'image',
    'system',
    'flysystem',
    'helfi_azure_fs',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
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

    // Skip itok validation.
    $this->config('image.settings')->set('allow_insecure_derivatives', TRUE)->save();

    $this->setSetting('flysystem', [
      'azure' => [
        'driver' => 'helfi_azure',
        'config' => [
          'name' => 'mock-name',
          'token' => 'mock-token',
          'container' => 'mock-container',
          'endpointSuffix' => 'core.windows.net',
          'protocol' => 'https',
        ],
        'cache' => TRUE,
      ],
    ]);
  }

  /**
   * Tests getExternalUrl.
   *
   * This test attempts to catch if any flysystem updates
   * break the features that we rely on.
   */
  public function testFlysystemServeRoute() : void {
    [$image] = $this->getTestFiles('image');

    $resource = fopen($image->uri, 'r');
    $stat = fstat($resource);
    $metadata = [
      'path' => 'styles/img_style/azure/helfi_testikuva.png',
      'dirname' => 'styles/img_style/azure',
      'size' => $stat['size'],
      'timestamp' => $stat['ctime'],
      'type' => 'file',
      'mimetype' => 'image/png',
    ];

    $adapter = $this->prophesize(AdapterInterface::class);
    $adapter
      ->has(Argument::any())
      ->willReturn(TRUE);

    $adapter
      ->readStream(Argument::any())
      ->willReturn($metadata + [
        'stream' => $resource,
      ]);

    $adapter
      ->getMetadata(Argument::any())
      ->willReturn($metadata);

    // Visibility is not supported by azure adapter.
    $adapter
      ->getVisibility(Argument::any())
      ->willThrow(new \LogicException("not supported"));

    $this->container->set('flysystem_factory', $this->getFlysystemFactory($adapter->reveal()));

    $imageStyle = ImageStyle::create([
      'name' => 'img_style',
    ]);
    $imageStyle->save();

    $uri = $imageStyle->buildUri('azure://helfi_testikuva.png');
    $request = $this->getMockedRequest($this->getExternalUrl($uri));
    $response = $this->processRequest($request);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertInstanceOf(BinaryFileResponse::class, $response);
    $this->assertEquals('helfi_testikuva.png', $response->getFile()->getFileName());
  }

  /**
   * Gets flysystem factory mock.
   */
  private function getFlysystemFactory(AdapterInterface $adapter): FlysystemFactory {
    return new class($this->container, $adapter) extends FlysystemFactory {

      /**
       * Constructs a new instance.
       */
      public function __construct(
        ContainerInterface $container,
        private readonly AdapterInterface $adapter,
      ) {
        parent::__construct(
          $container->get('plugin.manager.flysystem'),
          $container->get('stream_wrapper_manager'),
          $container->get('cache.flysystem'),
          $container->get('event_dispatcher'),
        );
      }

      /**
       * {@inheritDoc}
       */
      protected function getAdapter($scheme): AdapterInterface {
        return $this->adapter;
      }

    };
  }

}
