<?php

/**
 * @file
 * Contains \Drupal\bakery\Form\UncrumbleForm.
 */

namespace Drupal\bakery\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

class UncrumbleForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bakery_uncrumble';
  }

  /**
   * Only let people with actual problems mess with uncrumble.
   */
  public function access(AccountInterface $account, Request $request) {
    return true;
    return !$account->id() &&
    $request->getSession()->has('BAKERY_CRUMBLED') &&
    $request->getSession()->get('BAKERY_CRUMBLED');
  }

  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $form = parent::buildForm($form, $form_state);

    $site_name = \Drupal::config('system.site')->get('name');

    $cookie = _bakery_validate_cookie($request->cookies);

    // Analyze.
    $samemail = \Drupal::entityQuery('user')
      ->condition('u.uid', 0, '!=')
      ->condition('u.mail', '', '!=')
      // TODO this was previously LOWER()'d
      ->condition('mail', $cookie['mail'], '=')
      ->execute();

    $samename = \Drupal::entityQuery('user')
      ->condition('u.uid', 0, '!=')
      // TODO this was previously LOWER()'d
      ->condition('name', $cookie['name'], '=')
      ->execute();

    $form['name'] = array(
      '#type' => 'textfield',
      '#title' => t('Username'),
      '#value' => $cookie['name'],
      '#disabled' => TRUE,
      '#required' => TRUE,
    );

    $form['mail'] = array(
      '#type' => 'item',
      '#title' => t('Email address'),
      '#value' => $cookie['mail'],
      '#required' => TRUE,
    );

    $form['pass'] = array(
      '#type' => 'password',
      '#title' => t('Password'),
      '#description' => t('Enter the password that accompanies your username.'),
      '#required' => TRUE,
    );

    $form['submit'] = array('#type' => 'submit', '#value' => t('Repair account'), '#weight' => 2);

    $help = '';

    $count = db_select('users', 'u')->fields('u', array('uid'))->condition('init', $cookie['init'], '=')
      ->countQuery()->execute()->fetchField();
    if ($count > 1) {
      drupal_set_message(t('Multiple accounts are associated with your master account. This must be fixed manually. <a href="@contact">Please contact the site administrator.</a>', array('%email' => $cookie['mail'], '@contact' => \Drupal::config('bakery.settings')->get('bakery_master') .'contact')));
      $form['pass']['#disabled'] = TRUE;
      $form['submit']['#disabled'] = TRUE;
    }
    else if ($samename && $samemail && $samename->uid != $samemail->uid) {
      drupal_set_message(t('Both an account with matching name and an account with matching email address exist, but they are different accounts. This must be fixed manually. <a href="@contact">Please contact the site administrator.</a>', array('%email' => $cookie['mail'], '@contact' => \Drupal::config('bakery.settings')->get('bakery_master') .'contact')));
      $form['pass']['#disabled'] = TRUE;
      $form['submit']['#disabled'] = TRUE;
    }
    else if ($samename) {
      $help = t("An account with a matching username was found. Repairing it will reset the email address to match your master account. If this is the correct account, please enter your %site password.", array('%site' => $site_name));
      // This is a borderline information leak.
      //$form['mail']['#value'] = $samename->mail;
      $form['mail']['#value'] = t('<em>*hidden*</em>');
      $form['mail']['#description'] = t('Will change to %new.', array('%new' => $cookie['mail']));
    }
    else if ($samemail) {
      $help = t("An account with a matching email address was found. Repairing it will reset the username to match your master account. If this is the correct account, please enter your %site password.", array('%site' => $site_name));
      $form['name']['#value'] = $samemail->name;
      $form['name']['#description'] = t('Will change to %new.', array('%new' => $cookie['name']));
    }

    $form['help'] = array('#weight' => -10, '#markup' => $help);

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
