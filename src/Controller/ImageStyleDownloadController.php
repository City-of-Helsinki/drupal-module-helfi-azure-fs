<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Controller;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->storage = $container->get(BlobStorage::class);
    $instance->pageCacheKillSwitch = $container->get('page_cache_kill_switch');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(Request $request, $scheme, ImageStyleInterface $image_style, string $required_derivative_scheme): Response {
    $response = parent::deliver($request, $scheme, $image_style, $required_derivative_scheme);

    if (!$response instanceof BinaryFileResponse || $response->getStatusCode() !== Response::HTTP_OK) {
      return $response;
    }
    $derivativeUri = $response->getFile()->getPathname();

    $redirect = new TrustedRedirectResponse(
      $this->storage->getPublicUrl((string) StreamWrapperManager::getTarget($derivativeUri)),
      Response::HTTP_FOUND,
    );
    // Don't cache the redirect: the route doesn't vary by the file, which is
    // in the query, and the derivative can be flushed. The redirect is only
    // needed until the pages linking to the derivative are rendered again.
    $redirect->getCacheableMetadata()->setCacheMaxAge(0);
    // The page cache ignores the max-age.
    $this->pageCacheKillSwitch->trigger();

    return $redirect;
  }

}
