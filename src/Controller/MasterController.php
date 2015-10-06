<?php

/**
 * @file
 * Contains \Drupal\bakery\Controller\MasterController.
 */

namespace Drupal\bakery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\user\Entity\User;
use Drupal\user\Form\UserLoginForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MasterController extends ControllerBase {

  public function register(Request $request) {
    $cookie = \Drupal::service('bakery.taste_oatmeal_access')->tasteOatmealCookie($request);

    if (\Drupal::config('user.settings')->get('register') == USER_REGISTER_VISITORS) {
      // Users are allowed to register.
      $data = array();
      // Save errors.
      $errors = array();
      $name = trim($cookie['data']['name']);
      $mail = trim($cookie['data']['mail']);

      // Check if user exists with same email.
      $account = user_load_by_mail($mail);
      if ($account) {
        $errors['mail'] = 1;
      }
      else {
        // Check username.
        $account = user_load_by_name($name);
        if ($account) {
          $errors['name'] = 1;
        }
      }
    }
    else {
      \Drupal::logger('bakery')->error('Master Bakery site user registration is disabled but users are trying to register from a subsite.', array());
      $errors['register'] = 1;
    }

    if (empty($errors)) {
      // Create user.
      $userinfo = $cookie['data'];

      if (!$cookie['data']['pass']) {
        $pass = user_password();
      }
      else {
        $pass = $cookie['data']['pass'];
      }
      // Set additional properties.
      $userinfo['name'] = $name;
      $userinfo['mail'] = $mail;
      $userinfo['pass'] = $pass;
      $userinfo['init'] = $mail;
      $userinfo['status'] = 1;
      $userinfo['authname_bakery'] = $name;

      $account = User::create($userinfo);
      $account->save();

      // Set some info to return to the slave.
      $data['uid'] = $account->id();
      $data['mail'] = $mail;
      \Drupal::logger('user')->notice('New external user: %name using module bakery from slave !slave.', array('%name' => $account->getAccountName(), '!slave' => $cookie['slave']));

      // Redirect to slave.
      if (!\Drupal::config('user.settings')->get('verify_mail')) {
        // Create identification cookie and log user in.
        $init = _bakery_init_field($account->id());
        \Drupal::service('bakery.cookie_kitchen_subscriber')->bakeChocolateChipCookie($account->getAccountName(), $account->getEmail(), $init);
        bakery_user_external_login($account);
      }
      else {
        // The user needs to validate their email, redirect back to slave to
        // inform them.
        $errors['validate'] = 1;
      }
    }
    else {
      // There were errors.
      session_destroy();
    }

    // Redirect back to custom Bakery callback on slave.
    $data['errors'] = $errors;
    $data['name'] = $name;
    // Carry destination through return.
    if (isset($cookie['data']['destination'])) {
      $data['destination'] = $cookie['data']['destination'];
    }

    // Bake a new cookie for validation on the slave.
    $response = new RedirectResponse($cookie['slave'] . 'bakery');
    \Drupal::service('bakery.cookie_kitchen_subscriber')->bakeChocolateChipCookie($account->getAccountName(), $account->getEmail(), $init);
    bakery_bake_oatmeal_cookie($name, $data);
    drupal_goto();
  }

  /**
   * Special Bakery login callback authenticates the user and returns to slave.
   */
  public function login(Request $request) {
    /** @var \Drupal\bakery\EventSubscriber\CookieKitchen $cookie_kitchen */
    $cookie_kitchen = \Drupal::service('bakery.cookie_kitchen_subscriber');

    $cookie = \Drupal::service('bakery.taste_oatmeal_access')
      ->tasteOatmealCookie($request);

    // Make sure there are query defaults.
    $cookie['data'] += array('query' => array());
    // Remove the data pass cookie.
    $cookie_kitchen->eatCookies('OATMEAL');

    // First see if the user_login form validation has any errors for them.
    $name = trim($cookie['data']['name']);
    // Execute the login form which checks username, password, status and flood.
    $form_state = new FormState();
    $form_state->setValues($cookie['data']);
    \Drupal::formBuilder()->submitForm(UserLoginForm::class, $form_state);
    $errors = $form_state->getErrors();

    if (empty($errors)) {
      // Check if account credentials are correct.
      /** @var \Drupal\user\Entity\User $account */
      $account = user_load_by_name($name);
      if (!$account->id()) {
        // Passed all checks, create identification cookie and log in.
        $init = _bakery_init_field($account->id());

        \Drupal::service('bakery.cookie_kitchen_subscriber')
          ->bakeChocolateChipCookie($account->getAccountName(), $account->getEmail(), $init);
        user_login_finalize($account);
      }
      else {
        $errors['incorrect-credentials'] = 1;
      }
    }

    if (!empty($errors)) {
      // Report failed login.
      \Drupal::logger('user')->notice('Login attempt failed for %user.', array('%user' => $name));
      // Clear the messages on the master's session, since they were set during
      // drupal_form_submit() and will be displayed out of context.
      drupal_get_messages();
    }
    // Bake a new cookie for validation on the slave.
    $data = array(
      'errors' => $errors,
      'name' => $name,
    );
    // Carry destination through login.
    if (isset($cookie['data']['destination'])) {
      $data['destination'] = $cookie['data']['destination'];
    }
    // Carry other query parameters through login.
    $data['query'] = $cookie['data']['query'];
    \Drupal::service('bakery.cookie_kitchen_subscriber')->bakeOatmealCookie($name, $data);
    $response = new TrustedRedirectResponse($cookie['slave'] . 'bakery/login');
    $response->headers->set('test', 'value');
    return $response;
  }

  /**
   * Handle user creation validation.
   *
   * Gingerbread is read in during requirement and is stored in the sessions.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function eatGingerBread() {
    // Session was set in validate.
    $name = $_SESSION['bakery']['name'];
    unset($_SESSION['bakery']['name']);
    $or_email = $_SESSION['bakery']['or_email'];
    unset($_SESSION['bakery']['or_email']);
    $slave = $_SESSION['bakery']['slave'];
    unset($_SESSION['bakery']['slave']);
    $slave_uid = $_SESSION['bakery']['uid'];
    unset($_SESSION['bakery']['uid']);

    /** @var \Drupal\user\Entity\User $account */
    $account = user_load_by_name($name);
    if (!$account && $or_email) {
      $account = user_load_by_mail($name);
    }
    if ($account) {
      _bakery_save_slave_uid($account, $slave, $slave_uid);

      $payload = array();
      $payload['name'] = $account->getUsername();
      $payload['mail'] = $account->getEmail();
      $payload['uid'] = $account->id(); // For use in slave init field.
      // Add any synced fields.
      foreach (\Drupal::config('bakery.settings')->get('bakery_supported_fields') as $type => $enabled) {
        if ($enabled && $account->hasField($type)) {
          // @todo correct accessor.
//          $payload[$type] = $account->get($type);
        }
      }

      // Invoke implementations of hook_bakery_transmit() for syncing arbitrary
      // data.
      $payload['data'] = \Drupal::moduleHandler()->invokeAll('bakery_transmit', [NULL, $account]);

      $payload['timestamp'] = $_SERVER['REQUEST_TIME'];
      // Respond with encrypted and signed account information.
      return new Response(bakery_bake_data($payload));
    }

    return new Response('No account found', 409);
  }

  /**
   * Handle user validation.
   *
   * Thin mint cookie is read during requirements and is stored in the session.
   */
  public function eatThinMint() {
    // Session was set in validate.
    $name = $_SESSION['bakery']['name'];
    unset($_SESSION['bakery']['name']);
    $slave = $_SESSION['bakery']['slave'];
    unset($_SESSION['bakery']['slave']);
    $uid = $_SESSION['bakery']['uid'];
    unset($_SESSION['bakery']['uid']);

    /** @var \Drupal\user\Entity\User $account */
    $account = user_load_by_name($name);
    if ($account) {
      $account->setLastLoginTime($_SERVER['REQUEST_TIME']);
      $account->save();

      // Save UID provided by slave site.
      _bakery_save_slave_uid($account, $slave, $uid);
    }
  }

}
