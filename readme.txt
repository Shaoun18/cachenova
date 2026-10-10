=== CacheNova ===
Contributors: shaounchandrashill
Tags: cache, performance, object cache, page cache, developer tools
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically clears and manages WordPress cache layers on a scheduled basis, helping keep the site fresh and optimized without impacting performance.

== Description ==

A general-purpose cache-clearing plugin for any WordPress site. It works identically regardless of which theme or page builder you use — Divi, Elementor, Gutenberg, a classic theme, all of it — because it hooks into WordPress's own standard save/activate/deactivate events, not anything builder-specific.

**What it does, on any site:**

* Instantly clears every relevant cache the moment you save a page or post — the block editor, the Customizer, Divi Builder, or any other editor — no manual reload required.
* Runs a lightweight background job on a custom schedule (1, 3, 5, 10, 20, 30, or 60 minutes — your choice) to keep object cache and expired transients tidy, without touching site performance.
* Also clears automatically whenever any plugin on the site is activated, deactivated, or deleted.
* Detects and flushes WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, and Autoptimize automatically if they're active — no extra configuration.
* Optional built-in Public/Private page caching, with a true early-bypass mode that serves cached pages before WordPress even loads.
* Optional persistent object cache — APCu, Redis, Memcached, or a zero-dependency File-based fallback — auto-detected and auto-selected, no manual backend picking or connection testing required.
* Automatically refreshes its own page-cache and object-cache files whenever the plugin itself is updated, so bug fixes and speed improvements actually take effect instead of a stale copy silently running forever.
* Optional Database Optimization — clean post revisions, trashed posts, spam comments, and expired transients, plus a table-optimization pass. Off by default, only runs when explicitly requested.
* Ships with a modern React-based settings panel (built on WordPress's own bundled React — no separate build step, no bloat) with live toast notifications and a real-time countdown to the next scheduled clear.

**Why "light" vs "full" clearing matters**

Earlier cache-clearing approaches run a full clear (OPcache reset, full cache-plugin flush, filesystem walks) on every timer tick — which can itself slow a site down if it happens too often. This plugin splits the work:

* **Light clear** (runs on your background schedule): only the cache types you've switched on. Cheap, frequent, invisible.
* **Full clear** (runs only when something actually changed — a save, manual click, theme switch, or plugin update): everything, including OPcache and third-party cache plugin flushes.

**Automatic bonus support for Divi**

If Divi happens to be active on the site, the plugin detects it automatically and adds continuous purging of Divi's `et-cache` directory — a common source of "I edited it but nothing changed" on Divi sites — plus disabling Divi 4's Static CSS File Generation setting so the folder is never created in the first place. On Divi 5 (which removed that setting entirely), the plugin purges the folder after every request instead. None of this runs, and none of it is needed, on a site that isn't using Divi — the rest of the plugin works exactly the same either way.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip directly through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings → CacheNova to choose your auto-clear interval and toggle toast notifications.

No further configuration is required — Divi's Static CSS File Generation is disabled automatically the moment the plugin activates.

== Frequently Asked Questions ==

= Does this only work with Divi, or with any theme and plugin? =

Any theme, any plugin. The core of this plugin — clearing transients, object cache, OPcache, its own optional page cache, and popular third-party cache plugins, on a schedule and on every save — hooks into WordPress's own standard events (`save_post`, `switch_theme`, `activated_plugin`, and so on), which fire identically no matter what theme or page builder is active. Divi support is a bonus that's detected and applied automatically only if Divi happens to be installed; nothing Divi-specific runs, and nothing is required, on a site that isn't using it.

= Will this conflict with my other caching plugin? =

No. It detects common caching plugins (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, Autoptimize) and flushes them as part of a full clear, rather than working against them.

= Can I re-enable Divi's Static CSS File Generation? =

On Divi 4 sites: not while this plugin is active — it's re-enforced on every page load. On Divi 5: there is no longer a setting for this at all (Elegant Themes removed it), so the question doesn't apply — the plugin purges the et-cache folder after every request instead.

= Does this fully prevent the et-cache folder from ever existing on Divi 5? =

Not for the full duration of a request — Divi 5 has no opt-out for dynamic CSS generation, so it may briefly create files while serving a page. This plugin removes them again immediately after each request finishes, so the folder never persists or accumulates stale files, but it can't be prevented from being written to at all on Divi 5 the way it can on Divi 4.

= Does the background schedule slow my site down? =

No — the scheduled background tick only performs a lightweight clear (object cache and expired transients). The heavier full clear only runs when you actually save content in Divi or click "Clear Cache Now" manually.

= What does "Delete all data on uninstall" actually delete? =

With that toggle ON, deleting the plugin (not just deactivating it) removes its settings, its last-cleared/last-clear-type records, and the wp-content/cache/cachenova folder it uses for the built-in Public/Private page cache, then runs one final full-site cache clear. It does not touch Divi's own et-cache folder, since that belongs to Divi, not to this plugin. It's OFF by default, so a normal delete leaves your settings in place in case you reinstall later.

= What is the Persistent Object Cache feature, and is it safe to turn on? =

A persistent object cache lets WordPress reuse database query results across page loads (via APCu, Redis, or Memcached) instead of re-querying every time, which typically speeds up admin and front-end response times. It's OFF by default. Turning it on runs a real connection test first — nothing is installed if that test fails. If it succeeds, the plugin writes a wp-content/object-cache.php drop-in plus a small config file, and clears the cache once so it starts clean. It will never overwrite a different object-cache.php that's already there (from WP Redis, W3 Total Cache, etc.) — it detects that and asks you to remove the existing one first. Deactivating this plugin always removes the drop-in again, so caching goes back to WordPress's normal per-request behavior the moment you switch it off.

= Does this work outside of Divi? =

The cache-plugin detection and general WordPress cache clearing work on any site. The Static CSS File Generation override is Divi-specific and has no effect if Divi isn't active.

== Screenshots ==

1. CacheNova Settings Access — Find the CacheNova settings page under the WordPress Settings menu to configure cache management and optimization features.
2. CacheNova Dashboard — Manage automatic cache clearing, toast notifications, public/private/object/browser cache types, cache reports, database optimization, persistent object cache, early-bypass page cache, and uninstall data settings from a modern dashboard.

== Changelog ==

= 1.0.0 =
* Initial public release. Auto-clears transients, object cache, OPcache, and page cache on save or schedule, on any WordPress theme or plugin.
* Optional built-in Public/Private page cache with a true early-bypass mode (serves cached pages before WordPress even loads).
* Optional persistent object cache with automatic backend detection — Redis, Memcached, APCu, or a zero-dependency file-based fallback — no manual setup required.
* Database Optimization tool: clean post revisions, trashed posts, spam and trashed comments, and expired transients, plus a table-optimization pass. Off by default, only runs when explicitly requested.
* Automatically refreshes its own cache files whenever the plugin itself is updated, so bug fixes and improvements always take effect instead of a stale copy silently running forever.
* If Divi is active, also keeps its et-cache folder from ever accumulating stale files (Divi 4 and Divi 5).
* React-based settings dashboard (built on WordPress's own bundled React, no build step) with live status, toast notifications, and a real-time countdown to the next scheduled clear.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
