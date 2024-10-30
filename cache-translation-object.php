<?php
/*
  Plugin Name: Cache Translation Object
  Plugin URI: http://devel.kostdoktorn.se/cache-translation-object/
  Description: Substantially increase performance of localized WordPress by caching the translation (l10n) object. Works with most multiple locale plugins.
  Author: Johan Eenfeldt
  Author URI: http://devel.kostdoktorn.se
  Version: 1.2

  Copyright 2009 Johan Eenfeldt

  Licenced under the GNU GPL:

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/*
 * Todo:
 * - Add storage: memcached
 */

/* Array of available persistent storage for cached object. */
$cache_translation_storage =
	Array(
		  'apc'
		  => Array('description' => __('APC (Alternative PHP Cache)','cache-translation')
				   , 'status' => '')
		  , 'shm'
		  => Array('description' => __('SHMOP (Shared Memory)','cache-translation')
				   , 'status' => '')
		  , 'file'
		  => Array('description' => __('Filesystem','cache-translation')
				   , 'status' => '')
		  );

/* Dummy locale string used to disable textdomain load when object cached */
define('CACHE_TRANSLATION_DUMMY_LOCALE', 'cache-translation-cached');

$cache_translation_cached = false; /* Cached object loaded? */
$cache_translation_current_locale = null; /* Current locale */
$cache_translation_admin_warning = null;

/*
 * Plugin startup action/filter
 */
add_action('plugins_loaded', 'cachet_init');

/* Do this after any other filtering (for example multi-language plugins) */
add_filter('locale', 'cachet_filter_locale', 99999);


/* Plugin setup */
function cachet_init() {
	load_plugin_textdomain('cache-translation', false
						   , dirname(plugin_basename(__FILE__)) . '/languages');

	add_action('shutdown', 'cachet_action_store_translation');

	if (is_admin()) {
		$adminfile = dirname(__FILE__)
			. '/cache-translation-object-admin.php';
		require_once($adminfile);

		add_action('admin_menu', 'cachet_admin_menu');
		add_filter('plugin_action_links', 'cachet_filter_plugin_actions', 10, 2 );
	}

	/* Clear caches on plugin deactivation, do any upgrading necessary on activation */
	register_deactivation_hook(__FILE__, 'cachet_flush_all');
	register_activation_hook(__FILE__, 'cachet_upgrade_v1_1');
}

/* Check if caching is enabled */
function cachet_is_enabled() {
	global $cache_translation_admin_warning;

	return empty($cache_translation_admin_warning)
		&& get_option('cachet_enabled', false);
}

/* Check if selected storage is ok */
function cachet_ok() {
	return cachet_storage_available();
}

/*
 * Restore any stored translation object and stop WordPress from setup of
 * new textdomains by returning a dummy locale value.
 */
function cachet_filter_locale($locale) {
	global $l10n, $cache_translation_cached;

	if (!cachet_handle_this_call() || empty($locale))
		return $locale;

	if ($cache_translation_cached) {
		/* We have already restored a cached translation object */
		return CACHE_TRANSLATION_DUMMY_LOCALE;
	}

	if (!cachet_is_enabled())
		return $locale;

	if (!cachet_load_storage_fn()) {
		cachet_admin_warning(__('Could not load persistent storage functions! Please check storage type.'
								, 'cache-translation'));
		return $locale;
	}

	if (!cachet_ok()) {
		cachet_admin_warning(__('Persistent storage not available! Please check storage type.'
								, 'cache-translation'));
		return $locale;
	}

	global $cache_translation_current_locale;

	if (is_null($cache_translation_current_locale))
		$cache_translation_current_locale = $locale;
	elseif ($locale != $cache_translation_current_locale) {
		/* Someone changed what locale to use between calls? */
		cachet_admin_warning(sprintf(__('Non-consistent locale value (previous: %s, now: %s)! Plugin collision?'
										, 'cache-translation'), $cache_translation_current_locale, $locale));
		return $locale;
	}

	if (isset($l10n)) {
		/* This should only happen the first pageload, when have yet to cache object */
		if (cachet_size($locale))
			cachet_admin_warning(__('Translation object already exists! Plugin collision?'
									, 'cache-translation'));
		return $locale;
	}

	/*
	 * Ok, see if we can fetch a cached object
	 */

	$c = cachet_fetch($locale);
	if ($c === false)
		return $locale;

	if (!is_array($c)) {
		cachet_admin_warning(__('Stored object not array. Please report error!'
								, 'cache-translation'));
		return $locale;
	}

	/*
	 * The MO object creates a dynamic function to handle selection of plural
	 * form. These do not appear to survive the serialize/unserialize process.
	 *
	 * So we set them to null which makes the MO object recreate them.
	 */
	foreach ($c as $k => $mo) {
		if (!is_a($mo, "MO")) {
			cachet_admin_warning(__('Stored object not MO object. Please report error!'
									, 'cache-translation'));
			return $locale;
		}

		if (isset($mo->_gettext_select_plural_form)) {
			$mo->_gettext_select_plural_form = null;
		}
	}

	$l10n = $c;
	$cache_translation_cached = true;

	return CACHE_TRANSLATION_DUMMY_LOCALE;
}


/* Only handle calls from the load_*_textdomain functions */
function cachet_handle_this_call() {
	$whitelist = Array('load_default_textdomain', 'load_plugin_textdomain', 'load_theme_textdomain');

	if (!is_callable('debug_backtrace'))
		return true;

	$bt = debug_backtrace();
	foreach ( (array) $bt as $call ) {
		if ( in_array($call['function'], $whitelist) )
			return true;
	}

	return false;
}


/*
 * Store the complete translation object at shutdown (once).
 */
function cachet_action_store_translation() {
	global $l10n, $cache_translation_cached, $cache_translation_current_locale;

	if (!cachet_is_enabled() || !cachet_ok() || $cache_translation_cached
		|| empty($l10n) || empty($cache_translation_current_locale))
		return;

	cachet_store($cache_translation_current_locale, $l10n);
}


/*
 * Plugin storage functions
 *
 * Storage is declared in $cache_translation_storage, and implemented in file
 * named cache-translation-object-<type-name>.php which is loaded automatically
 * as needed.
 *
 * File must implement following api:
 * available
 *   - (bool) storage is usable and can be called. If not available
 *     cachet_storage_set_error($type, $msg) should tell admin why not.
 * 
 * store($locale, $data)
 *   - (bool) store locale data (overwrite any previous version)
 *
 * fetch($locale)
 *   - ($data | false) fetch locale data
 *
 * size($locale = null)
 *   - (int | string | false) specific locale or all storage, return own choice
 *     of bytes used or descriptive string of size usage. Return false if empty
 *
 *     Available: __('%d bytes cached','cache-translation')
 *     _n('from %d locale','from %d locales', $count, 'cache-translation')
 *
 * flush
 *   - (bool) delete all data
 *
 * (optional) debuginfo
 *   - Print relevant information to debug any storage related problems
 *
 * Each function is named "cachet_<type-name>_<operation>".
 */

/* Load storage type PHP file */
function cachet_load_storage_fn($type = null) {
	if (is_null($type))
		$type = cachet_get_type();

	if (!cachet_check_type($type))
		return false;

	$type_file = dirname(__FILE__) . '/cache-translation-object-' . $type . '.php';

	if (!is_readable($type_file))
		return false;

	return require_once($type_file);
}

/* Load files of all storage types */
function cachet_load_storage_fn_all() {
	global $cache_translation_storage;
	foreach ($cache_translation_storage as $type => $ignore) {
		cachet_load_storage_fn($type);
	}
}

/* Handle calls to persistent storage functions */
function cachet_call_storage($call, $type = null, $locale = null, $arg = null) {
	if (is_null($type))
		$type = cachet_get_type();

	/* Sanity check arguments */
	if (!is_string($call))
		return false;
	if ($call != 'available' && $call != 'debuginfo'
		&& !cachet_storage_available($type))
		return false;
	if (!is_null($locale) && !is_string($locale))
		return false;

	$fn = 'cachet_' . $type . '_' . $call;
	if (!function_exists($fn))
		return false;

	/* Set up function parameters */
	$params = Array();
	if (!is_null($locale))
		$params[] = $locale;
	if (!is_null($arg))
		$params[] = $arg;

	return call_user_func_array($fn, $params);
}

/* Check if storage type is available */
function cachet_storage_available($type = null) {
	return cachet_call_storage('available', $type);
}

/* Store locale data in persistent storage */
function cachet_store($locale, $data, $type = null) {
	return cachet_call_storage('store', $type, $locale, $data);
}

/* Fetch locale data from persistent storage */
function cachet_fetch($locale, $type = null) {
	return cachet_call_storage('fetch', $type, $locale);
}

/* Get string with size of all stored data (or null if empty) */
function cachet_size($locale = null, $type = null) {
	return cachet_call_storage('size', $type, $locale);
}

/* Flush all stored data */
function cachet_flush($type = null) {
	return cachet_call_storage('flush', $type);
}

/* Really make sure all cached data is gone */
function cachet_flush_all() {
	global $cache_translation_storage;

	foreach ($cache_translation_storage as $type => $ignore) {
		if (cachet_storage_available($type))
			cachet_flush($type);
	}
}

/* Print debug info */
function cachet_debuginfo($type = null) {
	global $cache_translation_storage, $cache_translation_cached, $cache_translation_current_locale, $cache_translation_admin_warning;

	echo('<h3>Cache Translation Object debug info</h3>'
		 . '----- start here -----'
		 . '<br/>Enabled: ' . get_option('cachet_enabled', false)
		 . '<br/>Type: ' . cachet_get_type()
		 . '<br/>Object cached: ');
	var_dump($cache_translation_cached);
	echo('<br/>Current locale: ');
	var_dump($cache_translation_current_locale);
	echo("<br/>");
	if (!empty($cache_translation_admin_warning))
		echo("Admin Warning: $cache_translation_admin_warning<br/>");

	if (!is_null($type))
		cachet_call_storage('debuginfo', $type);
	else {
		foreach ($cache_translation_storage as $type => $ignore) {
			cachet_call_storage('debuginfo', $type);
		}
	}
	echo('----- end here -----<br/>');
}


/*
 * Storage misc help function
 */

/* Set storage type status error */
function cachet_storage_set_error($type, $msg) {
	global $cache_translation_storage;

	if (!cachet_check_type($type) && !empty($type))
		return;

	$msg = '<strong>' . __('Error:','cache-translation') . '</strong> ' . $msg;

	$cache_translation_storage[$type]["status"] = $msg;
}


/* Get storage type status error */
function cachet_storage_get_error($type) {
	global $cache_translation_storage;

	if (!cachet_check_type($type) && !empty($type))
		return;

	return $cache_translation_storage[$type]["status"];
}


/* Check that type is legal (does not check availability) */
function cachet_check_type($type) {
	global $cache_translation_storage;
	return $type == '' || array_key_exists($type, $cache_translation_storage);
}


/* Get current storage type */
function cachet_get_type() {
	return get_option('cachet_type', '');
}


/* Show warning plugin warning -- only on admin pages */
function cachet_admin_warning($msg) {
	global $cache_translation_admin_warning;

	/* Only show to admin */
	if (!is_admin()) {
		$cache_translation_admin_warning .= 'disables-caching';
		return;
	}

	if (empty($cache_translation_admin_warning))
		add_action('admin_notices', 'cachet_admin_show_warning');
	else
		$cache_translation_admin_warning .= '<br />';

	$msg = '<a href="options-general.php?page=cache-translation-object">'
		. 'Cache Translation Object</a>: ' . $msg;

	$cache_translation_admin_warning .= $msg;
}


/* Delete any left over v1.0 caches */
function cachet_upgrade_v1_1() {
	cachet_load_storage_fn_all();

	if (cachet_apc_available()) {
		apc_delete('cache-translation-l10n');
	}

	if (cachet_shm_available()) {
		$file = dirname(plugin_basename(__FILE__))
			. '/cache-translation-object-shm.php';
		$shm_id = @shmop_open(ftok($file, 'c'), "w", 0, 0);
		if ($shm_id)
			shmop_delete($shm_id);
	}

	$file = WP_CONTENT_DIR . '/cachet-l10n-cache.inc';
	if (is_writable($file))
		unlink($file);
}
?>