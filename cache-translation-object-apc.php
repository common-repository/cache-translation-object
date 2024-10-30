<?php
/*
 * Cache Translation Object persistent storage -- APC (Alternative PHP Cache)
 *
 * Copyright 2009 Johan Eenfeldt
 *
 * Licenced under the GNU GPL:
 * 
 */
define('CACHE_TRANSLATION_APC_BASE', 'cache-translation-');

/* Is APC storage available? */
function cachet_apc_available() {
	$available = function_exists('apc_store');

	if (!$available) {
		cachet_storage_set_error('apc',  __('APC PHP module not available','cache-translation'));
	}

	return $available;
}

/* Store object in APC storage */
function cachet_apc_store($locale, $data) {
	return apc_store(_cachet_apc_get_key($locale), serialize($data));
}

/* Fetch object from APC storage */
function cachet_apc_fetch($locale) {
	return unserialize(apc_fetch(_cachet_apc_get_key($locale)));
}

/* Get size of object(s) in APC storage */
function cachet_apc_size($locale = null) {
	$i = apc_cache_info('user');

	$i = $i["cache_list"];
	$size = 0;
	$count = 0;
	$match = _cachet_apc_get_key($locale ? $locale : '');
	foreach($i as $k => $cached_obj) {
		if (strncmp($cached_obj["info"], $match, strlen($match)))
			continue;

		$size += $cached_obj["mem_size"];
		$count++;
	}

	if (!$count)
		return false;
	if ($count == 1)
		return $size;

	$msg = sprintf(__('%d bytes cached','cache-translation'), $size)
		. ' '
		. sprintf(_n('from %d locale','from %d locales'
					 , $count, 'cache-translation'), $count);

	return $msg;
}

/* Flush all objects in APC storage */
function cachet_apc_flush() {
	$i = apc_cache_info('user');

	$i = $i["cache_list"];
	$r = true;
	foreach($i as $k => $cached_obj) {
		if (!strncmp($cached_obj["info"]
					 , CACHE_TRANSLATION_APC_BASE
					 , strlen(CACHE_TRANSLATION_APC_BASE))) {
			$r &= apc_delete($cached_obj["info"]);
		}
	}
	return $r;
}

/* Optional API: dump debug info */
function cachet_apc_debuginfo() {
	$i = apc_cache_info('user');

	echo('<p><strong>APC</strong><br/>');
	if (!cachet_shm_available()) {
		echo('Storage disabled: (PHP PHP Extension not available)</p>');
		return;
	}
	echo('User variables: ');
	var_dump($i["cache_list"]);
	echo('</p>');
}

/*
 * Helpfunctions
 */

/* Get name to store locale as */
function _cachet_apc_get_key($locale) {
	return sanitize_file_name(CACHE_TRANSLATION_APC_BASE
							  . strtolower($locale));
}
?>