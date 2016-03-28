<?php

/**
 * @file
 * Contains \Drupal\bakery\EventSubscriber\ChocolateChip.
 */

namespace Drupal\bakery\EventSubscriber;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\system\Tests\Entity\EntityQueryTest;
use Drupal\user\Entity\User;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to manage chocolate chip cookies.
 */
class ChocolateChip implements EventSubscriberInterface {

  use UrlGeneratorTrait;
  use StringTranslationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * @var \Drupal\bakery\EventSubscriber\CookieKitchen
   */
  protected $kitchen;

  public function __construct(UrlGeneratorInterface $url_generator, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, CookieKitchen $cookie_kitchen) {
    $this->urlGenerator = $url_generator;
    $this->loggerFactory = $logger_factory;
    $this->config = $config_factory;
    $this->kitchen = $cookie_kitchen;
  }

  /**
   * Test identification cookie.
   */
  public function tasteChocolateChip(GetResponseEvent $event) {
    $cookie = $this->validateCookie($event->getRequest()->cookies);
    $config = $this->config->get('bakery.settings');

    // Continue if this is a valid cookie. That only happens for users who have
    // a current valid session on the master site.
    if ($cookie) {
      $user = \Drupal::currentUser();

      // Detect SSO cookie mismatch if there is already a valid session for user.
      if ($user->id() && $cookie['name'] !== $user->getAccountName()) {
        $this->logout($event);
        return;
      }

      if (!$user->id()) {
        // User is anonymous. If they do not have an account we'll create one by
        // requesting their information from the master site. If they do have an
        // account we may need to correct some disparant information.
        $account = \Drupal::entityManager()->getStorage('user')->loadByProperties(array('name' => $cookie['name'], 'mail' => $cookie['mail']));
        $account = reset($account);

        // Fix out of sync users with valid init.
        if (!$account && !$config->get('bakery_is_master') && $cookie['master']) {
          $count = \Drupal::entityQueryAggregate('user')
            ->condition('init', $cookie['init'])
            ->count()->execute();
          if ($count > 1) {
            // Uh oh.
            $this->logger('bakery')->error('Account uniqueness problem: Multiple users found with init %init.', ['%init' => $cookie['init']]);
            drupal_set_message($this->t('Account uniqueness problem detected. <a href="@contact">Please contact the site administrator.</a>',
              ['@contact' => $config->get('bakery_master') . 'contact']),
              'error');
          }
          if ($count == 1) {

            /** @var \Drupal\user\Entity\User $account */
            $account = \Drupal::entityManager()
              ->getStorage('user')
              ->loadByProperties(['init' => $cookie['init']]);
            if (is_array($account)) {
              $account = reset($account);
            }
            if ($account) {
              $this->logger('bakery')->notice('Fixing out of sync uid %uid. Changed name %name_old to %name_new, mail %mail_old to %mail_new.', [
                '%uid' => $account->id(),
                '%name_old' => $account->getAccountName(),
                '%name_new' => $cookie['name'],
                '%mail_old' => $account->getEmail(),
                '%mail_new' => $cookie['mail']
              ]);
              $account->setUsername($cookie['name']);
              $account->setEmail($cookie['mail']);
              $account->save();
              drupal_set_message('account sync.');

              $account = \Drupal::entityManager()->getStorage('user')->loadByProperties(array('name' => $cookie['name'], 'mail' => $cookie['mail']));
              $account = reset($account);
            }
          }
        }

        // Create the account if it doesn't exist.
        if (!$account && !$config->get('bakery_is_master') && $cookie['master']) {
          $checks = TRUE;
          $mail_count = \Drupal::entityQueryAggregate('user')
            ->condition('uid', $user->id(), '!=')
            ->condition('mail', '', '!=')
            // TODO this was previously LOWER()'d
            ->condition('mail', $cookie['mail'], '=')
            ->count();
          $name_count = \Drupal::entityQueryAggregate('user')
            ->condition('uid', $user->id(), '!=')
            // TODO this was previously LOWER()'d
            ->condition('name', $cookie['name'], '=')
            ->count();
          $init_count = \Drupal::entityQueryAggregate('user')
            ->condition('uid', $user->id(), '!=')
            ->condition('init', $cookie['init'], '=')
            // TODO this was previously LOWER()'d
            ->condition('name', $cookie['name'], '=')
            ->count();
          if ($mail_count->execute() > 0) {
            $checks = FALSE;
          }
          elseif ($name_count->execute() > 0) {
            $checks = FALSE;
          }
          elseif ($init_count->execute() > 0) {
            $checks = FALSE;
          }

          if ($checks) {
            // Request information from master to keep data in sync.
            $uid = bakery_request_account($cookie['name']);
            // In case the account creation failed we want to make sure the user
            // gets their bad cookie destroyed by not returning too early.
            if ($uid) {
              $account = User::load($uid);
            }
          }
          else {
            $core_config = \Drupal::config('system.site');
            drupal_set_message(t('Your user account on %site appears to have problems. Would you like to try to <a href="@url">repair it yourself</a>?', [
              '%site' => $core_config->get('name'),
              '@url' => $this->url('bakery.repair'),
            ]));
            drupal_set_message(Xss::filterAdmin($config->get('bakery_help_text')));
            $_SESSION['BAKERY_CRUMBLED'] = TRUE;
          }
        }

        /** @var \Drupal\user\Entity\User $account  */
        if ($account && $cookie['master'] && $account->id() && !$config->get('bakery_is_master') && $account->getInitialEmail() != $cookie['init']) {
          // User existed previously but init is wrong. Fix it to ensure account
          // remains in sync.

          // Make sure that there aren't any OTHER accounts with this init already.
          $count = \Drupal::entityQueryAggregate('user')
            ->condition('init', $cookie['init'], '=')
            ->count()->execute();
          if ($count == 0) {
            $account->set('init', $cookie['init']);
            $account->save();
            $this->logger('bakery')->notice('uid %uid out of sync. Changed init field from %oldinit to %newinit', [
              '%oldinit' => $account->get('init'),
              '%newinit' => $cookie['init'],
              '%uid' => $account->id(),
            ]);
          }
          else {
            // Username and email matched, but init belonged to a DIFFERENT account.
            // Something got seriously tangled up.
            $this->logger('bakery')->critical('Accounts mixed up! Username %user and init %init disagree with each other!', [
              '%user' => $account->getUsername(),
              '%init' => $cookie['init'],
            ]);
          }
        }

        if ($account && $user->id() == 0) {
          // If the login attempt fails we need to destroy the cookie to prevent
          // infinite redirects (with infinite failed login messages).
          $login = bakery_user_external_login($account);
          if ($login) {
            // If an anonymous user has just been logged in, trigger a 'refresh'
            // of the current page, ensuring that drupal_goto() does not override
            // the current page with the destination query.
            $query = [];
//            $query = \Drupal\Component\Utility\UrlHelper::filterQueryParameters();
//            unset($_GET['destination']);
            $event->setResponse($this->redirect('<current>', [], ['query' => $query]));
          }
        }
      }
//      elseif ($cookie) {
      // Re-bake cookie.
      $this->kitchen->bakeChocolateChipCookie($cookie['name'], $cookie['mail'], $cookie['init']);
//      }
    }
    else {
      // Invalid cookie.
      if ($cookie === FALSE) {
        $this->kitchen->eatCookies('CHOCOLATECHIP');
      }

      // No cookie or invalid cookie
      $user = \Drupal::currentUser();
      // Log out users that have lost their SSO cookie, with the exception of
      // UID 1 and any applied roles with permission to bypass.
      if ($user->id() > 1 && !$user->hasPermission('bypass bakery')) {
        $this->logger('bakery')
          ->notice('Logging out the user with the bad cookie.');
        drupal_set_message('Logging out.');
        $this->logout($event);
      }
    }
  }

  /**
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent|\Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   */
  protected function logout(GetResponseEvent $event) {
    user_logout();
    // @todo eat other cookies?
    // @todo replace "pass along query arguments" and "cache buster" code.
    $event->setResponse($this->redirect('<front>')->setMaxAge(-1));
  }

  /**
   * ...
   *
   * @param \Symfony\Component\HttpFoundation\ParameterBag $cookies
   * @return bool
   */
  protected function validateCookie(ParameterBag $cookies) {
    return _bakery_validate_cookie($cookies);
  }

  /**
   * Bake a cookie set it on the response.
   */
  protected function doCookieBaking(Response $response, $name, $mail, $init) {
    // _bakery_bake_chocolatechip_cookie($cookie['name'], $cookie['mail'], $cookie['init']);
    $config = \Drupal::config('bakery.settings');
    $key = $config->get('bakery_key');
    if (!empty($key)) {
      $type =  _bakery_cookie_name('CHOCOLATECHIP');
      $cookie_secure = false; //!$config->get('bakery_loose_ssl') && ini_get('session.cookie_secure');
      // Allow cookies to expire when the browser closes.
      $expire = ($config->get('bakery_freshness') > 0) ?
          $_SERVER['REQUEST_TIME'] + $config->get('bakery_freshness') :
          '0';
      $data = bakery_bake_data([
        'name' => $name,
        'mail' => $mail,
        'init' => $init,
        'master' => $config->get('bakery_is_master'),
        'calories' => 480,
        'timestamp' => $_SERVER['REQUEST_TIME'],
        'type' => $type,
      ]);
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

  /**
   * Eat up the chocolate chip cookie.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   * @param string $type
   */
  protected function doCookieEating(Response $response) {
    _bakery_eat_cookie($response, 'CHOCOLATECHIP');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = array();
    $events[KernelEvents::REQUEST][] = ['tasteChocolateChip', 20];
    return $events;
  }

  /**
   * Gets the logger for a specific channel.
   *
   * @param string $channel
   *   The name of the channel.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger for this channel.
   */
  public function logger($channel) {
    return $this->loggerFactory->get($channel);
  }

}
