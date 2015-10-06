<?php
/**
 * @file
 *
 */

namespace Drupal\bakery\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Symfony\Component\HttpFoundation\Request;

class TasteCookies implements AccessInterface {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * @var string
   */
  protected $type;

  public function __construct(ConfigFactoryInterface $config_factory, $type) {
    $this->config = $config_factory;
    $this->type = $type;
  }

  public function access(Request $request) {
    $access = FALSE;
    switch ($this->type) {
      case 'gingerbread':
        $access = $this->tasteGingerbreadCookie($request);
        break;

      case 'oatmeal':
        $access = $this->tasteOatmealCookie($request);
        break;

      case 'thinmint':
        $access = $this->tasteThinMintCookie($request);
        break;

      case 'stroopwafel':
        $access = $this->tasteStroopwafelCookie($request);
        break;
    }

    return $access ?
      AccessResult::allowed() :
      AccessResult::forbidden();
  }

  /**
   * Validate the account information request.
   */
  public function tasteGingerbreadCookie(Request $request) {
    $type = 'gingerbread';
    $data = $request->request->get('0[' . $type . ']', FALSE, TRUE);
    if (!$data) {
      return FALSE;
    }
    if (($cookie = bakery_validate_data($data, $type)) === FALSE) {
      return FALSE;
    }
    $_SESSION['bakery']['name'] = $cookie['name'];
    $_SESSION['bakery']['or_email'] = $cookie['or_email'];
    $_SESSION['bakery']['slave'] = $cookie['slave'];
    $_SESSION['bakery']['uid'] = $cookie['uid'];
    return TRUE;
  }

  /**
   * ...
   */
  public function tasteOatmealCookie(Request $request) {
    $key = \Drupal::config('bakery.settings')->get('bakery_key');
    $type = _bakery_cookie_name('OATMEAL');

    if (!$request->cookies->has($type) || !$key || !\Drupal::config('bakery.settings')->get('bakery_domain')) {
      return FALSE;
    }
    $data = bakery_validate_data($request->cookies->get($type), $type);
    if ($data !== FALSE) {
      return $data;
    }
    return FALSE;
  }

  /**
   * ...
   */
  public function tasteThinMintCookie(Request $request) {
    $type = 'thinmint';
    $data = $request->request->get('0[' . $type . ']', FALSE, TRUE);
    if (!$data) {
      return FALSE;
    }
    if (($cookie = bakery_validate_data($data, $type)) === FALSE) {
      return FALSE;
    }
    $_SESSION['bakery']['name'] = $cookie['name'];
    $_SESSION['bakery']['slave'] = $cookie['slave'];
    $_SESSION['bakery']['uid'] = $cookie['uid'];
    return TRUE;
  }

  public function tasteStroopwafelCookie(Request $request) {
    $type = 'stroopwafel';
    $data = $request->request->get('0[' . $type . ']', FALSE, TRUE);
    if (!$data) {
      return FALSE;
    }
    if (($cookie = bakery_validate_data($data, $type)) === FALSE) {
      return FALSE;
    }

    $_SESSION['bakery'] = unserialize($cookie['data']);
    $_SESSION['bakery']['uid'] = $cookie['uid'];
    $_SESSION['bakery']['category'] = $cookie['category'];
    return TRUE;
  }
}
