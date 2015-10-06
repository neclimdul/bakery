<?php
/**
 * @file
 *
 */

namespace Drupal\bakery\EventSubscriber;

use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CookieKitchen implements EventSubscriberInterface {

  use UrlGeneratorTrait;
  use StringTranslationTrait;

  /**
   * Array of arguments to bake cookies. Keyed by type.
   *
   * @var array
   */
  protected $baking = [];

  /**
   * A list of cookies in the cookie jar for Cookie Monster to eat.
   *
   * @var string[]
   */
  protected $cookieJar = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[KernelEvents::RESPONSE][] = ['gobbleCookies'];
    $events[KernelEvents::RESPONSE][] = ['bakeCookies'];
    return $events;
  }

  /**
   * nom nom nom.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   */
  public function gobbleCookies(FilterResponseEvent $event) {
    foreach ($this->cookieJar as $type => $eat) {
      _bakery_eat_cookie($event->getResponse(), $type);
    }
  }

  /**
   * Bake some fresh cookies for the user.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   */
  public function bakeCookies(FilterResponseEvent $event) {
    foreach ($this->baking as $type => $data) {
      $this->doCookieBaking($event->getResponse(), $type, $data);
    }
  }

  /**
   * Eat any cookies of a type.
   */
  public function eatCookies($type) {
    $this->cookieJar[$type] = TRUE;
    // Gobble up half baked cookies in the oven too.
    unset($this->baking[$type]);
  }

  /**
   * Add cookies to be baked.
   */
  protected function bakeCookie($type, $data) {
    // Set the new cookie as baking.
    $this->baking[$type] = $data;
    // Clean out cookies from the cookie jar so we don't eat freshly baked
    // cookies.
    unset($this->cookieJar[$type]);
  }

  /**
   * Add cookies to be baked.
   */
  public function bakeChocolateChipCookie($name, $mail, $init) {
    $config = \Drupal::config('bakery.settings');
    $this->bakeCookie('CHOCOLATECHIP', [
      'name' => $name,
      'mail' => $mail,
      'init' => $init,
      'master' => $config->get('bakery_is_master'),
      'calories' => 480,
      'timestamp' => $_SERVER['REQUEST_TIME'],
      'type' => 'CHOCOLATECHIP',
    ]);
  }

  /**
   * Bake an oatmeal cookie.
   */
  public function bakeOatmealCookie($name, $data) {
    $config = \Drupal::config('bakery.settings');
    $cookie_data = [
      'name' => $name,
      'data' => $data,
      'master' => $config->get('bakery_is_master'),
      'calories' => 320,
      'timestamp' => $_SERVER['REQUEST_TIME'],
      'type' => 'OATMEAL',
    ];
    if (!$config->get('bakery_is_master')) {
      $cookie_data['save'] = $this->url('<front>', [], ['absolute' => TRUE]);
    }
    $this->bakeCookie('OATMEAL', $cookie_data);
  }

  /**
   * Bake a cookie set it on the response.
   */
  protected function doCookieBaking(Response $response, $type, $data) {
    $config = \Drupal::config('bakery.settings');
    $key = $config->get('bakery_key');
    if (!empty($key)) {
      $type =  _bakery_cookie_name($type);
      $cookie_secure = false; //!$config->get('bakery_loose_ssl') && ini_get('session.cookie_secure');
      // Allow cookies to expire when the browser closes.
      $expire = ($config->get('bakery_freshness') > 0) ?
        $_SERVER['REQUEST_TIME'] + $config->get('bakery_freshness') :
        '0';
      $data = bakery_bake_data($data);
      $cookie = new Cookie(
        $type, $data,
        $expire,
        '/',
        $config->get('bakery_domain'),
        $cookie_secure,
        TRUE
      );
      $response->headers->setCookie($cookie);
    }
  }

}
