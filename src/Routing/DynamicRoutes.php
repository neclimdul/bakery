<?php

/**
 * @file
 * Contains \Drupal\bakery\Routing\DynamicRoutes
 */

namespace Drupal\bakery\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Routing\Route;

class DynamicRoutes {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory;
  }
  
  public function routes() {
    $config = $this->config->get('bakery.settings');
    if ($config->get('bakery_is_master')) {
      return $this->getMasterRoutes();
    }
    return $this->getSlaveRoutes();
  }

  protected function getMasterRoutes() {
    $routes = [];

    $routes['bakery.register'] = new Route('/bakery', [
        '_title' => 'Register',
        '_controller' => '\Drupal\bakery\Controller\MasterController::register',
      ],
      ['_bakery_taste_oatmeal' => 'TRUE']
    );
    $routes['bakery.login'] = new Route('/bakery/login', [
        '_title' => 'Login',
        '_controller' => '\Drupal\bakery\Controller\MasterController::login',
      ],
      ['_bakery_taste_oatmeal' => 'TRUE']
    );
    $routes['bakery.validate'] = new Route('/bakery/validate', [
        '_title' => 'Validate',
        '_controller' => '\Drupal\bakery\Controller\MasterController::eatThinMint',
      ],
      ['_bakery_taste_thinmint' => 'TRUE'] // requirements
    );
    $routes['bakery.create'] = new Route('/bakery/create', [
        '_title' => 'Bakery create',
        '_controller' => '\Drupal\bakery\Controller\MasterController::eatGingerBread',
      ],
      ['_bakery_taste_gingerbread' => 'TRUE']
    );

    return $routes;
  }

  protected function getSlaveRoutes() {
    $routes['bakery.register'] = new Route('/bakery', [
        '_title' => 'Register',
        '_controller' => '\Drupal\bakery\Controller\SlaveController::register',
      ],
      ['_bakery_taste_oatmeal' => 'TRUE']
    );
    $routes['bakery.login'] = new Route('/bakery/login', [
        '_title' => 'Login',
        '_controller' => '\Drupal\bakery\Controller\SlaveController::login',
      ],
      ['_bakery_taste_oatmeal' => 'TRUE']
    );
    $routes['bakery.update'] = new Route('/bakery/update', [
        '_title' => 'Update',
        '_controller' => '\Drupal\bakery\Controller\SlaveController::eatStroopwafel',
      ],
      ['_bakery_taste_stroopwafel' => 'TRUE']
    );
    $routes['bakery.repair'] = new Route('/bakery/repair', [
        '_controller' => '\Drupal\bakery\Controller\SlaveController::uncrumble',
        '_form' => '\Drupal\bakery\Form\uncrumbleForm',
        '_title' => 'Bakery create',
      ],
      ['_custom_access' =>  '\Drupal\bakery\Form\uncrumbleForm::access']
    );

    return $routes;
  }
}
