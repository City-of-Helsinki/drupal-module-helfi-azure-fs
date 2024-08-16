<?php

declare(strict_types=1);

namespace Drupal\helfi_azure_fs\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters image style routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) : void {
    $config = $this->configFactory->get('helfi_azure_fs.settings');

    // Skip if blob storage is not in use.
    if (!$config->get('use_blob_storage')) {
      return;
    }

    // Make sure image style URL requires 'azure' derivative scheme.
    if ($route = $collection->get('image.style_public')) {
      $route->setDefault('required_derivative_scheme', 'azure');
    }
  }

}
