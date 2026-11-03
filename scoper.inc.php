<?php
/**
 * PHP-Scoper: renames the plugin's libraries into its own namespace (D12),
 * so another plugin's copy of the same library can't load first. Used by
 * scripts/build.sh.
 *
 * @package Metatavu\WordPressIaC
 */

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'  => 'Metatavu\\WordPressIaC\\Vendor',
	'finders' => array(
		Finder::create()->files()->in( 'build/stage/metatavu-wordpress-iac/vendor/firebase/php-jwt' )->name( array( '*.php', 'LICENSE' ) )->exclude( array( 'tests' ) ),
	),
);
