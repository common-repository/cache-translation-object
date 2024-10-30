<?php
/*
 * Cache Translation Object Admin functions
 *
 * Copyright 2009 Johan Eenfeldt
 *
 * Licenced under the GNU GPL:
 * 
 */

/* Add admin/status page */
function cachet_admin_menu() {
	add_options_page('Cache Translation', 'Cache Translation', 8, 'cache-translation-object', 'cachet_option_page');
}


/* Add settings to plugin action links */
function cachet_filter_plugin_actions($links, $file) {
	static $this_plugin;

	if(!isset($this_plugin))
		$this_plugin = str_replace('-admin', '', plugin_basename(__FILE__));

	if($file == $this_plugin) {
		$settings_link = "<a href=\"options-general.php?page=cache-translation-object\">"
			. __('Settings', 'cache-translation') . '</a>';
		array_unshift( $links, $settings_link ); // before other links
	}

	return $links;
}


/* Actually show current warning messages */
function cachet_admin_show_warning() {
	global $cache_translation_admin_warning;

	if (empty($cache_translation_admin_warning))
		return;

	echo("<div id='message' class='error fade'><p>"
		 . "<a href=\"options-general.php?page=cache-translation-object\">"
		 . $cache_translation_admin_warning
		 . "</p></div>");
}


/* Plugin admin / status page */
function cachet_option_page() {
	if (!current_user_can('manage_options')) {
		wp_die('Sorry, but you do not have permissions to change settings.');
	}

	/* Make sure post was from this page */
	if (count($_POST) > 0) {
		check_admin_referer('cache-translation-options');
	}

	cachet_load_storage_fn_all();

	/* Should we clear log? */
	if (isset($_POST['delete_cache'])) {
		cachet_flush_all();
		echo '<div id="message" class="updated fade"><p>'
			. __('Cleared cache', 'cache-translation')
			. '</p></div>';
	}

	/* Should we update settings? */
	if (isset($_POST['update_settings'])) {
		$new_enabled = isset($_POST['enabled']) && !! $_POST['enabled'];
		update_option('cachet_enabled', $new_enabled);
		$new_type = isset($_POST['type']) ? $_POST['type'] : '';
		if (!cachet_check_type($new_type))
			$new_type = '';
		update_option('cachet_type', $new_type);
		cachet_flush_all();
		echo '<div id="message" class="updated fade"><p>'
			. __('Settings updated', 'cache-translation')
			. '</p></div>';
	}

	global $cache_translation_storage;

	$ok = cachet_ok();
	$enabled = cachet_is_enabled();
	$type = cachet_get_type();
	$size = cachet_size();
	if (!$size)
		$size = __('nothing cached', 'cache-translation');
	if (is_numeric($size))
		$size = sprintf(__('%d bytes cached','cache-translation'), intval($size));
	?>
	<div class="wrap">
	  <h2><?php echo __('Cache Translation Object Settings','cache-translation'); ?></h2>
		<form action="options-general.php?page=cache-translation-object" method="post" name="update_options">
		  <?php wp_nonce_field('cache-translation-options'); ?>
	    <table class="form-table">
		  <tr>
			<th scope="row" valign="top"><?php echo __('Status:','cache-translation'); ?></th>
			<td>
			  <div style="float: right"><a href="options-general.php?page=cache-translation-object&debuginfo=1"><?php echo(__('Debug Storage', 'cache-translation'));?></a></div>
			  <label>
				<input type="checkbox" id="enabled" name="enabled" <?php echo (($enabled ? ' CHECKED ' : '') . ($ok ? '' : ' DISABLED ')); ?> value="1" />
				<?php
				if ($enabled && $ok)
					echo __('Caching Active','cache-translation') . " ($size)";
				else
					echo __('Enable caching','cache-translation');
				?> 
			  </label>
			</td>
		  </tr>
		  <tr>
			<th scope="row" valign="top"><?php echo __('Supported storage:','cache-translation'); ?></th>
			<td>
			  <?php
	$storage_available = false;
	foreach ($cache_translation_storage as $t => $info) {
		$type_desc = $info["description"];
		$type_ok = cachet_storage_available($t);
		$type_error = cachet_storage_get_error($t);
		$storage_available |= $type_ok;
			  ?>
			  <label>
				<input type="radio" name="type" value="<?php echo $t;?>"
					onclick="document.getElementById('enabled').disabled = false;"
					<?php echo (($type_ok ? '' : ' DISABLED ') . ($type == $t ? ' checked ' : '')); ?> />
				<?php echo $type_desc; ?>
			  </label><?php if (!empty($type_error)) echo (' <i>' . $type_error . '</i>'); ?><br />
			  <?php }
			  if (!$storage_available) echo '<strong>' . __('No supported storage','cache-translation') . '</strong><br />';
			  ?>
			</td>
		  </tr>
		</table>
		  <p class="submit">
			<input name="update_settings" value="<?php echo __('Update Settings','cache-translation'); ?>" type="submit" /> &nbsp;
			<input name="delete_cache" value="<?php echo __('Clear Cached Objects','cache-translation'); ?>" type="submit" />
		  </p>
		</form>
		<?php
			if (isset($_GET['debuginfo']))
				cachet_debuginfo();
		?>
	</div>
	<?php
}
?>