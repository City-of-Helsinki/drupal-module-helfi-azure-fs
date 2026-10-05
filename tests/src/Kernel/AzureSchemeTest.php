<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_azure_fs\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\helfi_azure_fs\Controller\ImageStyleDownloadController;
use Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests the azure:// scheme integration without connecting to the storage.
 *
 * @see \Drupal\Tests\helfi_azure_fs\Kernel\AzureBlobStorageTest
 */
#[Group('helfi_azure_fs')]
#[RunTestsInSeparateProcesses]
class AzureSchemeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'image',
    'helfi_azure_fs',
  ];

  /**
   * Whether the blob storage is configured.
   */
  private bool $configured = TRUE;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    // The stream wrapper is registered when the container is built, so the
    // settings must be defined before.
    $this->setSetting('helfi_azure_fs', $this->configured ? [
      'name' => 'account',
      'container' => 'container',
      'token' => 'token',
      'public_url_base' => 'https://account.blob.core.windows.net/container',
    ] : NULL);
    parent::register($container);
  }

  /**
   * Gets the default file scheme.
   *
   * @return string|null
   *   The default scheme.
   */
  private function getDefaultScheme() : ?string {
    // KernelTestBase overrides the default scheme in $GLOBALS['config'], which
    // takes precedence over the module overrides.
    unset($GLOBALS['config']['system.file']['default_scheme']);
    $configFactory = \Drupal::configFactory();
    $configFactory->reset('system.file');

    return $configFactory->get('system.file')->get('default_scheme');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image']);
  }

  /**
   * Checks whether the given route exists.
   *
   * @param string $name
   *   The route name.
   *
   * @return bool
   *   TRUE if the route exists.
   */
  private function routeExists(string $name) : bool {
    try {
      \Drupal::service('router.route_provider')->getRouteByName($name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * Tests the stream wrapper registration.
   */
  public function testStreamWrapper() : void {
    $manager = $this->container->get('stream_wrapper_manager');

    $this->assertInstanceOf(AzureStreamWrapper::class, $manager->getViaScheme('azure'));
    // The core schemes are not affected.
    $this->assertInstanceOf(PublicStream::class, $manager->getViaScheme('public'));
    $this->assertInstanceOf(TemporaryStream::class, $manager->getViaScheme('temporary'));
    $this->assertSame('azure', $this->getDefaultScheme());
  }

  /**
   * Tests that nothing is registered when the storage isn't configured.
   */
  public function testNotConfigured() : void {
    $this->configured = FALSE;
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('router.builder')->rebuild();

    $this->assertFalse(\Drupal::service('stream_wrapper_manager')->isValidScheme('azure'));
    $this->assertSame('public', $this->getDefaultScheme());
    $this->assertFalse($this->routeExists('helfi_azure_fs.image_style'));
  }

  /**
   * Tests the image style routes.
   */
  public function testRoutes() : void {
    $provider = $this->container->get('router.route_provider');

    $route = $provider->getRouteByName('helfi_azure_fs.image_style');
    $this->assertSame('/' . PublicStream::basePath() . '/styles/{image_style}/{scheme}', $route->getPath());
    $this->assertSame(ImageStyleDownloadController::class . '::deliver', $route->getDefault('_controller'));
    $this->assertSame('azure', $route->getDefault('required_derivative_scheme'));
    $this->assertSame('azure', $route->getRequirement('scheme'));
    $this->assertTrue($route->getDefault('_disable_route_normalizer'));

    // Core's route only serves the public:// derivatives now.
    $this->assertSame('public', $provider->getRouteByName('image.style_public')->getRequirement('scheme'));
  }

  /**
   * Tests that the derivative URLs are routed to the right controller.
   */
  #[DataProvider('routeMatchingData')]
  public function testRouteMatching(string $scheme, string $expectedRoute) : void {
    $path = '/' . PublicStream::basePath() . "/styles/thumbnail/$scheme/folder/image.jpg";
    $request = Request::create($path);
    $processed = $this->container->get('path_processor_manager')->processInbound($path, $request);

    $this->assertSame('folder/image.jpg', $request->query->get('file'));
    $routes = new RouteCollection();
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $routes->add($name, $route);
    }
    $match = (new UrlMatcher($routes, new RequestContext()))->match($processed);
    $this->assertSame($expectedRoute, $match['_route']);
    $this->assertSame($scheme, $match['scheme']);
  }

  /**
   * The data provider for testRouteMatching().
   *
   * @return array<int, array{string, string}>
   *   The data.
   */
  public static function routeMatchingData() : array {
    return [
      ['public', 'image.style_public'],
      ['azure', 'helfi_azure_fs.image_style'],
    ];
  }

  /**
   * Tests that the core schemes keep working.
   */
  #[DataProvider('coreSchemeData')]
  public function testCoreScheme(string $scheme) : void {
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $directory = "$scheme://helfi-azure-fs-test/" . $this->randomMachineName();
    $subdirectory = "$directory/sub";

    $this->assertTrue($fileSystem->prepareDirectory($subdirectory, FileSystemInterface::CREATE_DIRECTORY));
    $this->assertDirectoryExists($subdirectory);
    $this->assertNotFalse($fileSystem->realpath($directory));

    $file = $fileSystem->saveData('contents', "$directory/file.txt");
    $this->assertSame("$directory/file.txt", $file);
    $this->assertStringEqualsFile($file, 'contents');

    // Copying to a directory copies the file inside it.
    $this->assertSame("$subdirectory/file.txt", $fileSystem->copy($file, $subdirectory));
    $this->assertSame("$subdirectory/file_0.txt", $fileSystem->copy($file, $subdirectory));
    $this->assertSame("$directory/moved.txt", $fileSystem->move($file, "$directory/moved.txt"));
    $this->assertFileDoesNotExist($file);

    $this->assertSame("$scheme://", $fileSystem->dirname("$scheme://file.txt"));
    $this->assertSame($subdirectory, $fileSystem->dirname("$subdirectory/file.txt"));
    $this->assertCount(3, $fileSystem->scanDirectory($directory, '/.*/'));

    $fileSystem->deleteRecursive($directory);
    $this->assertDirectoryDoesNotExist($directory);
  }

  /**
   * The data provider for testCoreScheme().
   *
   * @return array<int, array{string}>
   *   The data.
   */
  public static function coreSchemeData() : array {
    return [
      ['public'],
      ['temporary'],
    ];
  }

  /**
   * Tests that the public:// files and derivatives keep their URLs.
   */
  public function testPublicUrls() : void {
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $generator = $this->container->get('file_url_generator');
    $basePath = PublicStream::basePath();

    $image = $fileSystem->copy($this->root . '/core/tests/fixtures/files/image-1.png', 'public://image-1.png', FileExists::Replace);
    $this->assertStringEndsWith("/$basePath/image-1.png", $generator->generateAbsoluteString($image));

    $style = ImageStyle::load('thumbnail');
    $this->assertTrue($style->createDerivative($image, $style->buildUri($image)));
    $this->assertFileExists($style->buildUri($image));
    $this->assertStringContainsString("/$basePath/styles/thumbnail/public/image-1.png", $style->buildUrl($image));
  }

}
