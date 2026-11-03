<?php
/**
 * Starts the plugin. The loader in mu-plugins/ requires this file.
 *
 * @package Metatavu\WordPressIaC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

\Metatavu\WordPressIaC\Plugin::boot( __DIR__ . '/config.php' );
