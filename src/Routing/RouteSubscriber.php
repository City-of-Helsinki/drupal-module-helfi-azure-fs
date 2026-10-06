<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\helfi_azure_fs\BlobStorage;
use Drupal\helfi_azure_fs\Controller\ImageStyleDownloadController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the image style route for the azure:// derivatives.
 *
 * The missing derivatives are linked to the public files directory, like
 * public:// derivatives, so they're generated on the first request.
 *
 * @see \Drupal\image\Routing\ImageStyleRoutes
 * @see \Drupal\image\PathProcessor\PathProcessorImageStyles
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\helfi_azure_fs\BlobStorage $storage
   *   The storage.
   */
  public function __construct(
    private readonly BlobStorage $storage,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if (!$this->storage->isConfigured()) {
      return;
    }
    $scheme = BlobStorage::SCHEME;

    // Core's route uses the same path for every scheme, but only serves the
    // public:// derivatives.
    $collection->get('image.style_public')?->setRequirement('scheme', 'public');

    $collection->add('helfi_azure_fs.image_style', new Route(
      '/' . PublicStream::basePath() . '/styles/{image_style}/{scheme}',
      [
        '_controller' => ImageStyleDownloadController::class . '::deliver',
        'required_derivative_scheme' => $scheme,
        // Don't let the redirect module add the language prefix.
        '_disable_route_normalizer' => TRUE,
      ],
      [
        '_access' => 'TRUE',
        'scheme' => preg_quote($scheme, '#'),
      ],
    ));
  }

}
