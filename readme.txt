=== Cache Translation Object ===
Contributors: johanee
Tags: cache, caching, performance, translation, localization, i18n
Requires at least: 2.8
Tested up to: 2.8.5
Stable tag: 1.2

Substantially increase performance of localized WordPress by caching the translation (l10n) object. Supports multiple languages.

== Description ==

On every pageload a localized WordPress site spends a huge amount of time setting up the translation (l10n) object. Startup for a localized site can be almost four times slower than an untranslated one.

This plugin stores the translation object between pageloads so it only has to be created once, resulting in significantly better performance (performance graph included with screenshot).

Plugin supports caching multiple languages (if you use a multi-language plugin).

Available storage: Plain file, APC (Alternative PHP Cache), or SHMOP (Shared Memory)

== Installation ==

1. Download and extract plugin files to a folder in your wp-content/plugin directory.
2. Activate the plugin through the WordPress admin interface.
3. Select an available storage on options page (see FAQ for more details if necessary).
4. File storage needs write permissions (once) to wp-content after install to set up the directory used for storage (`wp-content/cache/l10n-cache`). Once set up is finished wp-content should be kept write protected.
5. If you run multiple WordPress sites on the same host please consult the plugin homepage for more information.

If you have any questions or problems go to: http://devel.kostdoktorn.se/cache-translation-object

== Frequently Asked Questions ==

= Which storage is better / faster? =

The exact performance will depend on your configuration but in my testing APC is a tiny bit faster than shared memory which is a bit faster than plain file storage. Check out the plugin homepage for some graphs.

= What is APC (Alternative PHP Cache)? =

"The Alternative PHP Cache (APC) is a free and open opcode cache for PHP. Its goal is to provide a free, open, and robust framework for caching and optimizing PHP intermediate code."

It is not usually installed by default.

= What is SHMOP (Shared Memory)? =

SHMOP is a PHP interface to handle Unix shared memory segments. It should be available by default on Linux/Unix servers. On Windows it may be possible to enable in PHP.ini.

On some older systems (and Windows) shared memory may have problems when multiple languages are cached. (If `Open slots` in the debug storage information keeps decreasing you should choose a different storage type.)

= How do I make files writable? =

`http://codex.wordpress.org/Changing_File_Permissions`

File storage write files to `wp-config/cache/l10n-cache/`. These directories will be created automatically if `wp-config` is writable at first use. Once these sub-directories are created `wp-config` should be kept write protected.

= I want to change where the files are stored =

Currently you'll have to do it by hand. Find it at the top of wp-content/plugin/cache-translation-object/cache-translation-object-file.php

== Screenshots ==

1. Administration interface in WordPress 2.8.4
2. Performance graph of loading an unmodified WordPress 2.8.4 start page

== Change Log ==

= Version 1.2 =
* Whitelist get_locale calls to filter

= Version 1.1 =
* Rewritten to cache multiple locales separately
* Split out admin functions into separate file
* Handle old SHM with questionable delete behaviour by relocating as necessary.
* Add storage debug info link
* Do not default to any specific storage type, make user choose
* Tell admin when something is wrong (also better error handling).
* Added plugin localization
* Added Swedish translation

= Version 1.0 =
* Initial release
