<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Controller;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\helfi_azure_fs\BlobStorage;
use Drupal\image\Controller\ImageStyleDownloadController as CoreImageStyleDownloadController;
use Drupal\image\ImageStyleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates the image style derivatives stored in the blob storage.
 *
 * Core generates the derivative and serves it with a BinaryFileResponse,
 * which would stream it through PHP. Redirect to the blob storage instead.
 *
 * The pages link here only while the derivative is missing, see
 * \Drupal\helfi_azure_fs\StreamWrapper\AzureStreamWrapper::getExternalUrl().
 * Once it's generated, the pages are invalidated so that they link to the blob
 * storage directly.
 */
final class ImageStyleDownloadController extends CoreImageStyleDownloadController {

  /**
   * The storage.
   */
  private BlobStorage $storage;

  /**
   * The page cache kill switch.
   */
  private KillSwitch $pageCacheKillSwitch;

  /**
   * The cache tags invalidator.
   */
  private CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->storage = $container->get(BlobStorage::class);
    $instance->pageCacheKillSwitch = $container->get('page_cache_kill_switch');
    $instance->cacheTagsInvalidator = $container->get('cache_tags.invalidator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(Request $request, $scheme, ImageStyleInterface $image_style, string $required_derivative_scheme): Response {
    $imageUri = $this->streamWrapperManager->normalizeUri($scheme . '://' . $request->query->get('file'));

    // Redirect to an existing derivative right away. Core would load it with
    // the image toolkit first, which downloads it from the blob storage.
    $derivativeUri = $this->getExistingDerivative($request, $imageUri, $image_style, $required_derivative_scheme);
    if ($derivativeUri !== NULL) {
      return $this->redirectToDerivative($derivativeUri);
    }

    $response = parent::deliver($request, $scheme, $image_style, $required_derivative_scheme);

    if (!$response instanceof BinaryFileResponse || $response->getStatusCode() !== Response::HTTP_OK) {
      return $response;
    }
    // The pages rendered while the derivative was missing link here.
    $this->invalidateSourceFile($imageUri);

    return $this->redirectToDerivative($response->getFile()->getPathname());
  }

  /**
   * Gets the derivative URI, if the request is valid and the derivative exists.
   *
   * Requires a valid token like core, but not the source image.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $imageUri
   *   The normalized image URI from the request.
   * @param \Drupal\image\ImageStyleInterface $imageStyle
   *   The image style.
   * @param string $requiredDerivativeScheme
   *   The required scheme for the derivative.
   *
   * @return string|null
   *   The derivative URI, or NULL to let core handle the request.
   */
  private function getExistingDerivative(Request $request, string $imageUri, ImageStyleInterface $imageStyle, string $requiredDerivativeScheme): ?string {
    $token = (string) $request->query->get(ImageStyleInterface::TOKEN, '');

    if (!hash_equals($imageStyle->getPathToken($imageUri), $token)) {
      return NULL;
    }
    $derivativeUri = $imageStyle->buildUri($imageUri);

    if (StreamWrapperManager::getScheme($derivativeUri) !== $requiredDerivativeScheme || !file_exists($derivativeUri)) {
      return NULL;
    }
    return $derivativeUri;
  }

  /**
   * Invalidates the cache tags of the source file.
   *
   * @param string $imageUri
   *   The image URI from the request. When the image style converts the
   *   extension, it's the source URI with the new extension added.
   */
  private function invalidateSourceFile(string $imageUri): void {
    $uris = array_unique([$imageUri, self::getUriWithoutConvertedExtension($imageUri)]);
    $files = $this->entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $uris]);

    foreach ($files as $file) {
      $this->cacheTagsInvalidator->invalidateTags($file->getCacheTagsToInvalidate());
    }
  }

  /**
   * Redirects to the given derivative in the blob storage.
   *
   * @param string $derivativeUri
   *   The derivative URI.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   The redirect.
   */
  private function redirectToDerivative(string $derivativeUri): TrustedRedirectResponse {
    $redirect = new TrustedRedirectResponse(
      $this->storage->getPublicUrl((string) StreamWrapperManager::getTarget($derivativeUri)),
      Response::HTTP_FOUND,
    );
    // Don't cache the redirect: the route doesn't vary by the file, which is
    // in the query, and the derivative can be flushed.
    $redirect->getCacheableMetadata()->setCacheMaxAge(0);
    // The page cache ignores the max-age.
    $this->pageCacheKillSwitch->trigger();

    return $redirect;
  }

}
