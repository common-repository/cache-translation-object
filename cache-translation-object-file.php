<?php
/*
 * Cache Translation Object persistent storage -- Filesystem
 *
 * Copyright 2009 Johan Eenfeldt
 *
 * Licenced under the GNU GPL:
 * 
 */

/* Directory structure in which to save files. Directories which might need to
 * be created should be separate */
function _cachet_file_get_dir_structure() {
	return Array(WP_CONTENT_DIR, 'cache', 'l10n-cache');
}

/* Is storage available? (file writeable) */
function cachet_file_available() {
	$dir_array = _cachet_file_get_dir_structure();
	$writable = false;
	while (true) {
		$path = implode('/', $dir_array);
		$writable |= file_exists($path) && is_writable($path);

		if ($writable || file_exists($path) || !array_pop($dir_array))
			break;
	}

	if (!$writable) {
		cachet_storage_set_error('file',  sprintf(__('%s is write-protected','cache-translation'), _cachet_file_get_path()));
	}

	return $writable;
}

/* Save file */
function cachet_file_store($locale, $data) {
	return file_put_contents(_cachet_file_get_filename($locale), serialize($data));
}

/* Read file */
function cachet_file_fetch($locale) {
	$raw_data = @file_get_contents(_cachet_file_get_filename($locale, false));

	if ($raw_data === false)
		return false;

	return unserialize($raw_data);
}

/* Get size of file(s) */
function cachet_file_size($locale = null) {
	if ($locale)
		return @filesize(_cachet_file_get_filename($locale, false));

	$path = _cachet_file_get_path();
	$handle = @opendir($path);

	if (!$handle)
		return false;

	$size = 0;
	$count = 0;

	while (false !== ($file = readdir($handle))) {
		$file = $path . '/' . $file;
		if (!is_file($file))
			continue;
		$size += @filesize($file);
		$count++;
	}

	closedir($handle);

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

/* Delete file */
function cachet_file_flush() {
	$path = _cachet_file_get_path();
	$handle = @opendir($path);

	if (!$handle)
		return true;

	while (false !== ($file = readdir($handle))) {
		$file = $path . '/' . $file;
		if (!is_file($file))
			continue;
		unlink($file);
	}

	return true;
}

/*
 * Filename/directory help functions
 */

/*
 * Get filename (including full path) for $locale. If $create_path is set path
 * will be created as necessary
 */
function _cachet_file_get_filename($locale, $create_path = true) {
	$file = sanitize_file_name('locale-' . strtolower($locale));
	
	return _cachet_file_get_path($create_path) . '/' . $file;
}

/*
 * Get full path to store in. If $create is set path will be created as
 * necessary
 */
function _cachet_file_get_path($create = false) {
	static $path;
	$dir_array = _cachet_file_get_dir_structure();

	if (!isset($path))
		$path = implode('/', $dir_array);

	if (!$create || file_exists($path))
		return $path;

	$temp_path = '';
	for ($i = 0; $i < count($dir_array); $i++) {
		if (!empty($temp_path))
			$temp_path .= '/';
		$temp_path .= $dir_array[$i];

		if (!file_exists($temp_path))
			if (!mkdir($temp_path, 0755))
				break;
	}

	return $path;
}


/* Optional API: dump debug info */
function cachet_file_debuginfo() {
	echo('<p><strong>File</strong><br/>'
		 . 'Storage directory structure:<br/>');

	$dir_array = _cachet_file_get_dir_structure();
	$extended_dir = Array();
	$writable = false;
	while (count($dir_array) > 0) {
		$path = implode('/', $dir_array);
		$top = array_pop($dir_array);
		$writable |= file_exists($path) && is_writable($path);
		array_push($extended_dir, Array('path' => $top
										, 'exists' => file_exists($path)
										, 'writable' => $writable));
	}

	while (!empty($extended_dir)) {
		$e = array_pop($extended_dir);
		$path = $e['path'];
		$exists = $e['exists'] ? '' : '[do not exist]';
		$writable = $e['exists'] && !$e['writable'] ? '[<strong>writeprotected</strong>]' : '';
		echo("<code>$path</code> <i>$exists $writable</i><br/>");
	}
	
	if (!cachet_file_available()) {
		echo('Storage disabled: Storage path is not writable </p>');
		return;
	}

	echo('Storage directory:<br/>');

	$path = _cachet_file_get_path();
	$handle = @opendir($path);

	$count = 0;

	if (!$handle) {
		echo('Could not open directory<br/>');
	} else {
		while (false !== ($file_name = readdir($handle))) {
			if ($file_name == '.' || $file_name == '..')
				continue;

			$file = $path . '/' . $file_name;
			$dir = is_dir($file) ? '[dir]' : '';
			$size = !empty($dir) ? 0 : @filesize($file);
			$size = $size > 0 ? "($size)" : '';
			$writable = !is_writable($file) ? '[<strong>writeprotected</strong>]' : '';

			echo("<code>$file_name</code> $dir <i>$size $writable</i><br/>");
			$count++;
		}

		if (!$count)
			echo('Directory empty<br/>');

		closedir($handle);
	}

	echo('</p>');
}
?>