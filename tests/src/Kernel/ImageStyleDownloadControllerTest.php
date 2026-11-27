<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\file\Entity\File;
use Drupal\helfi_azure_fs\Controller\ImageStyleDownloadController;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the image style controller without connecting to the storage.
 *
 * The azure:// files are stored in the public files directory. Only the
 * redirect URLs point to the blob storage.
 *
 * @see \Drupal\Tests\helfi_azure_fs\Kernel\AzureBlobStorageTest::testImageStyleController()
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class ImageStyleDownloadControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'helfi_azure_fs',
  ];

  /**
   * The cache item that stands for a page showing the image.
   */
  private const string PAGE_CID = 'page';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $this->setSetting('helfi_azure_fs', [
      'name' => 'account',
      'container' => 'container',
      'token' => 'token',
      'public_url_base' => 'https://account.blob.core.windows.net/container',
    ]);
    $this->setSetting('file_additional_public_schemes', ['azure']);
    parent::register($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    // Store the azure:// files in the public files directory. The file
    // operations go through the PHP stream wrapper.
    stream_wrapper_unregister('azure');
    stream_wrapper_register('azure', PublicStream::class);

    ImageStyle::create([
      'name' => 'scaled',
      'label' => 'Scaled',
      'effects' => [
        [
          'id' => 'image_scale',
          'weight' => 0,
          'data' => ['width' => 10, 'height' => 10, 'upscale' => FALSE],
        ],
      ],
    ])->save();
    ImageStyle::create([
      'name' => 'converted',
      'label' => 'Converted',
      'effects' => [
        [
          'id' => 'image_convert',
          'weight' => 0,
          'data' => ['extension' => 'jpg'],
        ],
      ],
    ])->save();
  }

  /**
   * Creates a source image and its file entity.
   *
   * @param string $name
   *   The file name.
   *
   * @return \Drupal\file\Entity\File
   *   The file.
   */
  private function createImage(string $name): File {
    $uri = $this->container->get('file_system')->copy($this->root . '/core/tests/fixtures/files/image-1.png', 'azure://images/' . $name);
    $file = File::create(['uri' => $uri]);
    $file->save();
    return $file;
  }

  /**
   * Caches a page that shows the given file.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file.
   */
  private function cachePage(File $file): void {
    $this->container->get('cache.render')->set(self::PAGE_CID, 'page', Cache::PERMANENT, $file->getCacheTags());
  }

  /**
   * Checks whether the page is still cached.
   *
   * @return bool
   *   TRUE if the page is cached.
   */
  private function isPageCached(): bool {
    return $this->container->get('cache.render')->get(self::PAGE_CID) !== FALSE;
  }

  /**
   * Requests the derivative.
   *
   * @param \Drupal\image\ImageStyleInterface $style
   *   The image style.
   * @param string $file
   *   The file query parameter: the target of the derivative URL.
   * @param string $token
   *   The token.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function deliver(ImageStyleInterface $style, string $file, string $token): Response {
    $request = Request::create('/', 'GET', [
      'file' => $file,
      ImageStyleInterface::TOKEN => $token,
    ]);
    return ImageStyleDownloadController::create($this->container)->deliver($request, 'azure', $style, 'azure');
  }

  /**
   * Asserts that the response redirects to the derivative.
   *
   * @param string $expectedPath
   *   The derivative path in the blob storage.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   */
  private function assertRedirect(string $expectedPath, Response $response): void {
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('https://account.blob.core.windows.net/container/' . $expectedPath, $response->getTargetUrl());
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * Generating a derivative invalidates the pages showing the source file.
   */
  public function testGeneratingInvalidatesSourceFile(): void {
    $style = ImageStyle::load('scaled');
    $file = $this->createImage('image.png');
    $token = $style->getPathToken($file->getFileUri());
    $this->cachePage($file);

    $response = $this->deliver($style, 'images/image.png', $token);

    $this->assertRedirect('styles/scaled/azure/images/image.png', $response);
    $this->assertFileExists('azure://styles/scaled/azure/images/image.png');
    $this->assertFalse($this->isPageCached());
  }

  /**
   * The source file is found when the image style converts the extension.
   */
  public function testGeneratingConvertedInvalidatesSourceFile(): void {
    $style = ImageStyle::load('converted');
    $file = $this->createImage('image.png');
    $token = $style->getPathToken($file->getFileUri());
    $this->cachePage($file);

    $response = $this->deliver($style, 'images/image.png.jpg', $token);

    $this->assertRedirect('styles/converted/azure/images/image.png.jpg', $response);
    $this->assertFalse($this->isPageCached());
  }

  /**
   * Existing derivatives are redirected to without core's processing.
   */
  public function testExistingDerivativeIsRedirected(): void {
    $style = ImageStyle::load('converted');
    $file = $this->createImage('image.png');
    $token = $style->getPathToken($file->getFileUri());
    $this->deliver($style, 'images/image.png.jpg', $token);
    $this->cachePage($file);

    // Core would answer 404 for the missing source.
    $this->container->get('file_system')->delete($file->getFileUri());
    $response = $this->deliver($style, 'images/image.png.jpg', $token);

    $this->assertRedirect('styles/converted/azure/images/image.png.jpg', $response);
    // Nothing was generated, so the pages are up to date.
    $this->assertTrue($this->isPageCached());
  }

  /**
   * Existing derivatives still require a valid token.
   */
  public function testExistingDerivativeRequiresToken(): void {
    $style = ImageStyle::load('scaled');
    $file = $this->createImage('image.png');
    $this->deliver($style, 'images/image.png', $style->getPathToken($file->getFileUri()));

    $this->expectException(NotFoundHttpException::class);
    $this->deliver($style, 'images/image.png', 'invalid');
  }

}
