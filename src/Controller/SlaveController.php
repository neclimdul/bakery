<?php

/**
 * @file
 * Contains \Drupal\bakery\Controller\SlaveController.
 */

namespace Drupal\bakery\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SlaveController extends ControllerBase {

  /**
   * @var \Drupal\Core\Utility\UnroutedUrlAssemblerInterface
   */
  protected $unroutedUrlAssembler;

  /**
   * Constructs a RedirectResponseSubscriber object.
   *
   * @param \Drupal\Core\Utility\UnroutedUrlAssemblerInterface $url_assembler
   *   The unrouted URL assembler service.
   */
  public function __construct(UnroutedUrlAssemblerInterface $url_assembler) {
    $this->unroutedUrlAssembler = $url_assembler;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('unrouted_url_assembler'));
  }

  /**
   * Custom return for slave registration process.
   *
   * Redirects to the homepage on success or to the register page if there was a problem.
   *
   * @todo correctly handle already logged in users.
   */
  public function register(Request $request) {
    /** @var \Drupal\bakery\EventSubscriber\CookieKitchen $cookie_kitchen */
    $cookie_kitchen = \Drupal::service('bakery.cookie_kitchen_subscriber');

    $cookie = \Drupal::service('bakery.taste_oatmeal_access')
      ->tasteOatmealCookie($request);

    // Valid cookie, now destroy it.
    $cookie_kitchen->eatCookies('OATMEAL');

    $errors = $cookie['data']['errors'];
    if (empty($errors)) {
      drupal_set_message(t('Registration successful. You are now logged in.'));
      // Destination in cookie was set before user left this site, extract it to
      // be sure destination workflow is followed.
      if (empty($cookie['data']['destination'])) {
        return $this->redirect('<front>');
      }
      // Redirect to destination.
      return new RedirectResponse($this->getDestinationAsAbsoluteUrl($cookie['data']['destination'], $request->getSchemeAndHttpHost()));
    }

    if (!empty($errors['register'])) {
      drupal_set_message(t('Registration is not enabled on @master. Please contact a site administrator.', array(
        '@master' => \Drupal::config('bakery.settings')
          ->get('bakery_master')
      )), 'error');
      \Drupal::logger('bakery')
        ->error('Master Bakery site user registration is disabled', array());
    }
    if (!empty($errors['validate'])) {
      // If the user must validate their email then we need to create an
      // account for them on the slave site.
      $account = User::create([
        'name' => $cookie['name'],
        'mail' => $cookie['data']['mail'],
        'init' => _bakery_init_field($cookie['data']['uid']),
        'status' => 1,
        'pass' => user_password(),
      ]);
      $account->save();

      // Notify the user that they need to validate their email.
      _user_mail_notify('register_no_approval_required', $account);
      unset($_SESSION['bakery']['register']);
      drupal_set_message(t('A welcome message with further instructions has been sent to your e-mail address.'));
    }
    if (!empty($errors['name'])) {
      drupal_set_message(t('Name is already taken.'), 'error');
    }
    if (!empty($errors['mail'])) {
      drupal_set_message(t('E-mail address is already registered.'), 'error');
    }
    if (!empty($errors['mail_denied'])) {
      drupal_set_message(t('The e-mail address has been denied access..'), 'error');
    }
    if (!empty($errors['name_denied'])) {
      drupal_set_message(t('The name has been denied access..'), 'error');
    }
    // There are errors so keep user on registration page.
    return $this->redirect('user.register');
  }

  /**
   * Custom return for errors during slave login process.
   */
  public function login(Request $request) {
    /** @var \Drupal\bakery\EventSubscriber\CookieKitchen $cookie_kitchen */
    $cookie_kitchen = \Drupal::service('bakery.cookie_kitchen_subscriber');

    $cookie = \Drupal::service('bakery.taste_oatmeal_access')
      ->tasteOatmealCookie($request);

    // If user is logged in and visiting this page redirect to <front>.
    if (!$cookie) {
      if (\Drupal::currentUser()->isAuthenticated()) {
        return $this->redirect('<front>');
      }
      else {
        throw new AccessDeniedHttpException();
      }
    }

    // Valid cookie, now destroy it.
    $cookie_kitchen->eatCookies('OATMEAL');

    // Make sure we always have a default query key.
    $cookie['data'] += array('query' => array());

    if (!empty($cookie['data']['errors'])) {
      $errors = $cookie['data']['errors'];
      if (!empty($errors['incorrect-credentials'])) {
        drupal_set_message(t('Sorry, unrecognized username or password.'), 'error');
      }
      elseif (!empty($errors['name'])) {
        // In case an attacker got the hash we filter the argument here to avoid
        // exposing a XSS vector.
        drupal_set_message(Xss::filter($errors['name']), 'error');
      }
    }

    // Prepare the url options array to pass to drupal_goto().
    $options = array('query' => $cookie['data']['query']);
    if (empty($cookie['data']['destination'])) {
      return $this->redirect('user.page', $options);
    }
    $destination = $cookie['data']['destination'];
    if (($pos = strpos($cookie['data']['destination'], '?')) !== FALSE) {
      // Destination contains query arguments that must be extracted.
      $destination = substr($cookie['data']['destination'], 0, $pos);
      parse_str(substr($cookie['data']['destination'], $pos + 1), $tmp);
      $options['query'] += $tmp;
    }
    return new RedirectResponse($this->getDestinationAsAbsoluteUrl($destination, $request->getSchemeAndHttpHost()));
  }

  /**
   * Menu callback, invoked on the slave
   */
  public function eatStroopwafel() {
    // the session got set during validation
    $stroopwafel = $_SESSION['bakery'];
    unset($_SESSION['bakery']);

    $response = new Response();

    $init = _bakery_init_field($stroopwafel['uid']);

    // check if the user exists.
    /** @var \Drupal\user\Entity\User[] $accounts */
    $accounts = \Drupal::entityManager()->getStorage('user')->loadByProperties(array('init' => $init));
    if (empty($accounts)) {
      // user not present
      $response->setContent(t('Account not found on %slave.', array('%slave' => \Drupal::config('system.site')->get('name'))));
    }
    else {
      $account = reset($accounts);
      $response->headers->add('X-Drupal-bakery-UID', $account->id());

      // If profile field is enabled we manually save profile fields along the way.
      $fields = array();
      foreach (\Drupal::config('bakery.settings')->get('bakery_supported_fields') as $type => $value) {
        // @TODO implement this.
        break;
        if ($value) {
          // If the field is set in the cookie it's being updated, otherwise we'll
          // populate $fields with the existing values so nothing is lost.
          if (isset($stroopwafel[$type])) {
            $fields[$type] = $stroopwafel[$type];
          }
          else {
            $fields[$type] = $account->$type;
          }
        }
      }
      $status = $account->save();

      if ($status === FALSE) {
        \Drupal::logger('bakery')->error('User update from name %name_old to %name_new, mail %mail_old to %mail_new failed.', [
          '%name_old' => $account->getAccountName(),
          '%name_new' => $stroopwafel['name'],
          '%mail_old' => $account->getEmail(),
          '%mail_new' => $stroopwafel['mail'],
        ]);
        // user not present
        $response->setContent(t('There was a problem updating your account on %slave. Please contact the administrator.', [
          '%slave' => \Drupal::config('system.site')->get('name'),
        ]));
        $response->setStatusCode(409);
      }
      else {
        \Drupal::logger('bakery')->notice('user updated name %name_old to %name_new, mail %mail_old to %mail_new.', [
          '%name_old' => $account->getAccountName(),
          '%name_new' => $stroopwafel['name'],
          '%mail_old' => $account->getEmail(),
          '%mail_new' => $stroopwafel['mail'],
        ]);
        $response->setContent(t('Successfully updated account on %slave.', [
          '%slave' => \Drupal::config('system.site')->get('name'),
        ]));
      }

      // Invoke hook_bakery_receive().
      \Drupal::moduleHandler()->invokeAll('bakery_receive', [$account, $stroopwafel]);
    }

    \Drupal::moduleHandler()->invokeAll('exit');
    return $response;
  }

  /**
   * Converts the passed in destination into an absolute URL.
   *
   * @param string $destination
   *   The path for the destination. In case it starts with a slash it should
   *   have the base path included already.
   * @param string $scheme_and_host
   *   The scheme and host string of the current request.
   *
   * @return string
   *   The destination as absolute URL.
   */
  protected function getDestinationAsAbsoluteUrl($destination, $scheme_and_host) {
    if (!UrlHelper::isExternal($destination)) {
      // The destination query parameter can be a relative URL in the sense of
      // not including the scheme and host, but its path is expected to be
      // absolute (start with a '/'). For such a case, prepend the scheme and
      // host, because the 'Location' header must be absolute.
      if (strpos($destination, '/') === 0) {
        $destination = $scheme_and_host . $destination;
      }
      else {
        // Legacy destination query parameters can be internal paths that have
        // not yet been converted to URLs.
        $destination = UrlHelper::parse($destination);
        $uri = 'base:' . $destination['path'];
        $options = [
          'query' => $destination['query'],
          'fragment' => $destination['fragment'],
          'absolute' => TRUE,
        ];
        // Treat this as if it's user input of a path relative to the site's
        // base URL.
        $destination = $this->unroutedUrlAssembler->assemble($uri, $options);
      }
    }
    return $destination;
  }

}
