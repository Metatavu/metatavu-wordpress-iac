<?php
/**
 * Plugin Name: Metatavu WordPress IaC
 * Description: Lets OpenTofu, running in a project's CI, manage this site's whitelisted options, plugins and themes. Authenticated with GitHub OIDC.
 * Version: 0.2.1
 * Requires PHP: 8.4
 * Author: Metatavu
 * License: GPL-2.0-or-later
 *
 * @package Metatavu\WordPressIaC
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/metatavu-wordpress-iac/plugin.php';
