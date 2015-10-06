<?php

/**
 * @file
 * Drush integration for Bakery.
 */

/**
 * Implementation of hook_drush_help().
 */
function bakery_drush_help($section) {
  switch ($section) {
    case 'drush:bakery-add-slave':
      return dt('Update the Bakery configuration to add a slave site.');
  }
}

/**
 * Implementation of hook_drush_command().
 */
function bakery_drush_command() {
  $items['bakery-add-slave'] = array(
    'description' => 'Add a Bakery slave site.',
    'arguments' => array(
      'slave' => 'Slave site URL, starts with http and ending with /.',
    ),
  );
  return $items;
}

function drush_bakery_add_slave($slave) {
  // @FIXME
// Could not extract the default value because it is either indeterminate, or
// not scalar. You'll need to provide a default value in
// config/install/bakery.settings.yml and config/schema/bakery.schema.yml.
$slaves = \Drupal::config('bakery.settings')->get('bakery_slaves');
  $slaves[] = $slave;
  \Drupal::configFactory()->getEditable('bakery.settings')->set('bakery_slaves', $slaves)->save();
}
