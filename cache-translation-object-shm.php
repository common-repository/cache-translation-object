<?php
/* 
 * Cache Translation Object persistent storage -- SHMOP shared memory
 *
 * Copyright 2009 Johan Eenfeldt
 *
 * Licenced under the GNU GPL:
 * 
 */

$cachet_shm_locale_records = null; // keep track of where locale is allocated

/* Is shared memory storage available? */
function cachet_shm_available() {
	$available = function_exists('shmop_open');

	if (!$available) {
		cachet_storage_set_error('shm',  __('Shared Memory PHP module not available','cache-translation'));
	}

	return $available;
}

/* Fetch object from shared memory storage */
function cachet_shm_fetch($locale) {
	if (is_null($locale))
		return false;

	return _cachet_shm_fetch($locale);
}

/* Store object in shared memory storage */
function cachet_shm_store($locale, &$data) {
	if (is_null($locale) || !$data)
		return false;

	return _cachet_shm_store($locale, $data);
}

/* Get size of object in shared memory storage */
function cachet_shm_size($locale = null) {
	global $cachet_shm_locale_records;

	if ($locale)
		return _cachet_shm_size($locale);

	_cachet_shm_setup();
	if (!isset($cachet_shm_locale_records) || !is_array($cachet_shm_locale_records))
		return false;

	$size = 0;
	$count = 0;

	foreach ($cachet_shm_locale_records as $locale => $c) {
		$size += _cachet_shm_size($locale);
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

/* Delete shared memory storage */
function cachet_shm_flush() {
	global $cachet_shm_locale_records;

	_cachet_shm_setup();
	if (!isset($cachet_shm_locale_records) || !is_array($cachet_shm_locale_records))
		return false;

	foreach ($cachet_shm_locale_records as $locale => $c)
		_cachet_shm_delete($locale);

	$cachet_shm_locale_records = null;
	_cachet_shm_delete(null); // delete now obsolete records
}

/*
 * Shared memory help functions
 */

if (!function_exists('ftok')) {
	/*
	 * Doesn't exist on Windows?
	 *
	 * Code from PHP ftok manual page
	 */

	/* Get system-unique id from file */
	function ftok($pathname, $proj_id) {
		$st = @stat($pathname);
		if (!$st) {
			return -1;
		}
 
		$key = sprintf("%u", (($st['ino'] & 0xffff) | (($st['dev'] & 0xff) << 16) | ((ord($proj_id) & 0xff) << 24)));
		return $key;
	}
}

/* Lower-level fetch implementation */
function _cachet_shm_fetch($locale) {
	$key = _cachet_shm_get_key($locale);

	if (!$key)
		return false;

	$shm_id = _cachet_shm_access($key);

	if (!$shm_id)
		return false;

	$data = unserialize(shmop_read($shm_id, 0, 0));
	shmop_close($shm_id);
	return $data;
}

/* Lower-level store implementation */
function _cachet_shm_store($locale, &$data) {
	/* TODO: refactor this function */

	$key = _cachet_shm_get_key($locale, true);

	if (!$key)
		return false;

	$shm_data = serialize($data);
	$size = strlen($shm_data);

	if ($locale == '')
		$allocate = max($size, 1024); /* Hack: register enough for allowed entries */
	else
		$allocate = $size;

	$shm_id = _cachet_shm_create($key, $allocate);

	if (!$shm_id) {
		/* This probably means it already exists. Try to overwrite.
		 *
		 * For the records array this is common. For ordinary objects it
		 * appears that it is sometimes hard (especially in some versions of 
		 * Windows) to actually delete SHM object which means we can get here
		 * after a cach flush.
		 */
		$shm_id = _cachet_shm_access($key, "w");

		if (!$shm_id)
			return false; /* No -- some other problem? */

		$shm_size = shmop_size($shm_id);

		if ($size > $shm_size) {
			/* Too big, try to relocate object */
			$r = _cachet_shm_relocate($locale, &$data);

			if (!$r) {
				/* Nope. */
				shmop_close($shm_id);
				return $r;
			}

			/* Object relocated -- delete old one */
			shmop_delete($shm_id);
			shmop_close($shm_id);
			return $r;
		}
	}

	$bytes_written = shmop_write($shm_id, $shm_data, 0);
	shmop_close($shm_id);
	return $bytes_written;
}

/* Try to relocate locale (caller needs to delete old one if necessary) */
function _cachet_shm_relocate($locale, &$data) {
	if ($locale == '') {
		/*
		 * It is the records object. We do not handle that.
		 * Try to add an admin warning and hope for the best...
		 */
		cachet_admin_warning(sprintf(__('Storage error in %s (%d). Please report.'
										, 'cache-translation')
									 , 'SHMOP', 1));
		return false;
	}

	/* Ordinary object -- try to relocate it */
	$r = _cachet_shm_add_locale($locale);

	if (!$r) {
		/* No open slots */
		cachet_admin_warning(sprintf(__('Storage error in %s (%d). Please report.'
										, 'cache-translation')
									 , 'SHMOP', 2));
		return false;
	}

	/* Try store using new key. This will recurse until we succeed or run out
	 * of key space. Hopefully this corner case doesn't need better. */
	$r = _cachet_shm_store($locale, &$data);

	if (!$r) {
		/*
		 * Could not store in new slot. Instead of rolling things back,
		 * just delete object (it will be regenerated on next pageload)
		 */
		_cachet_shm_delete($locale);
		cachet_admin_warning(sprintf(__('Storage error in %s (%d). Please report.'
										, 'cache-translation')
									 , 'SHMOP', 3));
		return false;
	}

	return $r;
}

/* Get size of one locale (shm segment) */
function _cachet_shm_size($locale) {
	$key = _cachet_shm_get_key($locale);

	if (!$key)
		return false;

	$shm_id = _cachet_shm_access($key);

	if (!$shm_id)
		return 0;

	$shm_size = shmop_size($shm_id);
	shmop_close($shm_id);
	return $shm_size;
}

/* Delete one locale (shm segment) */
function _cachet_shm_delete($locale) {
	$key = _cachet_shm_get_key($locale);

	if (!$key)
		return false;

	$shm_id = _cachet_shm_access($key, "w");
	if (!$shm_id)
		return false;

	$result = shmop_delete($shm_id);
	shmop_close($shm_id);

	if ($locale)
		_cachet_shm_remove_locale($locale);

	return $result;
}

/* Setup global object to keep track of locale allocations */
function _cachet_shm_setup() {
	global $cachet_shm_locale_records;

	if (isset($cachet_shm_locale_records))
		return;

	/* fetch record -- stored as locale null */
	$cachet_shm_locale_records = _cachet_shm_fetch(null);

	if ($cachet_shm_locale_records == false
		|| !is_array($cachet_shm_locale_records)) {
		$cachet_shm_locale_records = Array();
		return;
	}
}

/* Get highest key char in use + 1 */
function _cachet_shm_get_next_key_char() {
	global $cachet_shm_locale_records;

	/* _cachet_shm_setup() must have been called before this */

	$nr = count($cachet_shm_locale_records);

	if ($nr > 0) {
		/* One more than previous highest */
		asort($cachet_shm_locale_records);
		$next = end($cachet_shm_locale_records) + 1;
	} else {
		/* 'A' is reserved for locale records array */
		$next = ord('B');
	}

	/* Limit of 56 translations */
	if ($next > ord('z'))
		return false;

	return $next;
}

/* Add locale (or give new key char), store new records object, return new char id */
function _cachet_shm_add_locale($locale) {
	global $cachet_shm_locale_records;

	_cachet_shm_setup();

	$next = _cachet_shm_get_next_key_char();
	if (!$next)
		return false;

	$prev = null;
	if (isset($cachet_shm_locale_records[$locale]))
		$prev = $cachet_shm_locale_records[$locale];

	$cachet_shm_locale_records[$locale] = $next;

	/* Store records array */
	if (_cachet_shm_store(null, $cachet_shm_locale_records))
		return $next;
	else {
		/* Failed to store records -- roll back this thing */
		if ($prev)
			$cachet_shm_locale_records[$locale] = $prev;
		else
			unset($cachet_shm_locale_records[$locale]);
		return false;
	}
}

/* Remove locale, store new records object */
function _cachet_shm_remove_locale($locale) {
	global $cachet_shm_locale_records;

	_cachet_shm_setup();

	if (empty($cachet_shm_locale_records) || !is_array($cachet_shm_locale_records))
		return;

	if (!isset($cachet_shm_locale_records[$locale]))
		return;

	unset($cachet_shm_locale_records[$locale]);

	/* Store records array */
	_cachet_shm_store(null, $cachet_shm_locale_records);
}

/* Get our system-unique shared memory key */
function _cachet_shm_get_key($locale, $create = false) {
	global $cachet_shm_locale_records;

	// null locale reserverd for locale_records
	if (is_null($locale))
		return ftok(__FILE__, 'A');

	// get char to use from records array
	_cachet_shm_setup();
	if (!isset($cachet_shm_locale_records) || !is_array($cachet_shm_locale_records))
		return null;

	$c = null;
	if (isset($cachet_shm_locale_records[$locale]))
		$c = $cachet_shm_locale_records[$locale];
	elseif ($create)
		$c = _cachet_shm_add_locale($locale);

	return $c ? ftok(__FILE__, chr($c)) : null;
}

/* Open existing shared memory storage */
function _cachet_shm_access($key, $mode = "a") {
	return @shmop_open($key, $mode, 0, 0);
}

/* Create new shared memory storage */
function _cachet_shm_create($key, $size) {
	return @shmop_open($key, "n", 0644, $size);
}

/* Optional API: dump debug info */
function cachet_shm_debuginfo() {
	global $cachet_shm_locale_records;

	echo('<p><strong>Shared Memory</strong><br/>');
	if (!cachet_shm_available()) {
		echo('Storage disabled: (SHMOP PHP Extension not available)</p>');
		return;
	}
	
	echo('In-memory record array: ');
	if (empty($cachet_shm_locale_records)) {
		echo("Empty<br/>");
	} else {
		var_dump($cachet_shm_locale_records);
		echo('<br />');

		$count = 0;
		$max = 0;
		foreach ($cachet_shm_locale_records as $locale => $c) {
			if ($c > $max)
				$max = $c;
			$count++;
			echo("locale object: $locale, c: $c, size: ");
			var_dump(_cachet_shm_size($locale));
			echo('<br />');
		}
		echo('Relocations: ' . ($max - ord('A') - $count) . '<br/>');
		echo('Open slots: ' . (ord('z') - $max) . '<br/>');
	}

	_cachet_shm_setup();
	echo("Stored records array: ");
	if (!isset($cachet_shm_locale_records) || !is_array($cachet_shm_locale_records))
		echo("Empty<br/>");
	else {
		echo(' space: ');
		var_dump(_cachet_shm_size(null));
		$obj = _cachet_shm_fetch(null);
		echo(' size: ' . strlen(serialize($obj)));
		echo(' content: ');
		var_dump($obj);

		foreach ($cachet_shm_locale_records as $locale => $c) {
			echo("<br />locale object: $locale, c: $c, size: ");
			var_dump(_cachet_shm_size($locale));
		}
	}
	echo('</p>');
}
?>