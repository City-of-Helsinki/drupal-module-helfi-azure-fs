<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\helfi_azure_fs\Flysystem\Adapter\AzureBlobStorageAdapter;
use GuzzleHttp\Psr7\Response;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\GetBlobPropertiesResult;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests AzureBlobStorageAdapter.
 *
 * @group helfi_azure_fs
 */
class AzureBlobStorageAdapterTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Tests metadata.
   */
  public function testGetMetadata() : void {
    $date = new \DateTime();
    $client = $this->prophesize(BlobRestProxy::class);
    $client->getBlobProperties('test', 'test')
      ->shouldBeCalled()
      ->willReturn(GetBlobPropertiesResult::create([
        'last-modified' => $date->format(Resources::AZURE_DATE_FORMAT),
        'content-length' => 1,
      ]));
    $sut = new AzureBlobStorageAdapter($client->reveal(), 'test');
    $this->assertEquals([
      'path' => 'test',
      'timestamp' => $date->format('U'),
      'dirname' => '',
      'mimetype' => NULL,
      'size' => 1,
      'type' => 'file',
    ], $sut->getMetadata('test'));
  }

  /**
   * Tests metadata with asset paths.
   *
   * @dataProvider getMetadataAssets
   */
  public function testGetMetadataAsset(string $assetPath) : void {
    $client = $this->prophesize(BlobRestProxy::class);
    $client->getBlobProperties('test', $assetPath)
      ->shouldBeCalled()
      ->willThrow(new ServiceException(new Response(404)));
    $sut = new AzureBlobStorageAdapter($client->reveal(), 'test');
    $this->assertEquals([
      'path' => $assetPath,
      'type' => 'dir',
    ], $sut->getMetadata($assetPath));
  }

  /**
   * Gets metadata asset path.
   *
   * @return array[]
   *   The assets.
   */
  public function getMetadataAssets() : array {
    return [
      ['css'],
      ['js'],
    ];
  }

}
