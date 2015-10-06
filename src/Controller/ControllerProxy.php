<?php

/**
 * @file
 * Contains  Drupal\bakery\Controller\ControllerProxy.
 */

namespace Drupal\bakery\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ControllerProxy implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Core\Controller\ControllerBase
   */
  protected $realController;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $config */
    $config = $container->get('config.factory');
    if ($config->get('bakery_is_master')) {
      return MasterController::create($container);
    }

    return SlaveController::create($container);
  }

  public function register() {}

}
