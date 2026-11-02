=== CacheNova – Auto Clear Cache, Browser Cache & Database Optimizer ===
Contributors: shaounchandrashill
Tags: clear cache, auto clear cache, browser cache, cache, performance
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto clear cache on a schedule or on save. Clear browser, page and object cache, plus database optimization. Works with Divi, Elementor, WooCommerce.

== Description ==

**CacheNova is an auto cache clear plugin for WordPress.** It clears your site's cache automatically, on a schedule you choose and every time you change something, so visitors always see your latest edits. Need it right now? Click **Clear Cache Now** in the top admin bar, on any page, with no page reload.

Works with any theme and any page builder: Divi, Elementor, Beaver Builder, Bricks, Oxygen, the block editor and classic themes.

= Clear cache automatically (auto cache clear) =

* **Auto clear cache on a schedule** — every 10, 20 or 30 minutes, every hour, every day, every 7 days or every 30 days.
* **Auto clear cache on save** — clears the moment you update a post or page, save in the Customizer, edit a menu, switch theme, or activate, deactivate, update or delete a plugin.
* **Clear Cache Now button** in the WordPress admin bar and inside the plugin dashboard, with a live toast message and a spinning icon while it works.
* **Smart light and full clears** — the frequent scheduled clear is lightweight; the full clear only runs when something really changed.

= Browser cache clear =

Turn on **Browser Cache** and every cache clear gives your site's own CSS and JavaScript files a fresh version number, so visitors' browsers download the newest files instead of showing an old copy. Cache-Control headers are sent only to logged-out visitors, and never on cart, checkout, account, search, 404, preview or password-protected pages. It is off by default.

= Clears every cache layer =

* Object cache and expired transients
* PHP OPcache
* Built-in public and private page cache, plus an optional early-bypass mode that serves cached pages before WordPress loads
* Optional persistent object cache: Redis, Memcached, APCu or a file-based fallback, picked automatically
* **Other cache plugins, when active:** WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, Autoptimize, WP Fastest Cache, Breeze, WP-Optimize, Cache Enabler, Hummingbird and NitroPack
* **Page builders and WooCommerce, when active:** Elementor, Beaver Builder, Bricks, Oxygen and WooCommerce product transients
* **Hosting caches, when detected:** WP Engine, Kinsta, Pantheon, Pagely and Cloudflare plugin hooks
* Developers can hook their own cleanup into `rlcc_purge_third_party_caches`

= Database optimization (automatic or manual) =

A dedicated **Database Optimization** tab keeps your database small and fast:

* Auto-optimize **every 1 day, every 7 days or every month**. Each run clears all post revisions, trashed posts, spam comments, trashed comments and expired transients, then optimizes your database tables.
* Or run a manual cleanup whenever you want, choosing exactly which items to clean.
* Off by default. Nothing is deleted until you turn it on and confirm.

= Divi cache clear (automatic bonus support) =

If Divi is active, CacheNova clears Divi's `et-cache` folder every time you clear the cache, fixing the common "I edited it but nothing changed" problem. It handles read-only files and symlinks safely, and only ever removes the `et-cache` folder inside wp-content. On Divi 4 it also turns off Static CSS File Generation. Nothing Divi-specific runs on sites that don't use Divi.

= A modern, simple dashboard =

* Built on WordPress's own bundled React, with no extra bundles or build step
* Clear confirmation windows before anything risky, and live toast notifications
* Real-time countdown to the next automatic clear
* Last clear report that shows exactly what was cleared and verified

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or go to Plugins → Add New → Upload Plugin and upload the zip.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Settings → CacheNova to choose how often to auto clear the cache. Optional features such as Browser Cache and Database Optimization are off until you turn them on.

== Frequently Asked Questions ==

= How do I clear the cache in WordPress? =

Click **Clear Cache Now** in the top admin bar, or open Settings → CacheNova and use the button at the top. It clears every cache layer in one click and shows a message when it is done.

= How do I auto clear cache in WordPress? =

Open Settings → CacheNova and choose **Auto-clear every** (10, 20 or 30 minutes, 1 hour, 1 day, 7 days or 30 days). CacheNova also clears automatically whenever you save content, change a menu or theme, or activate, deactivate or update a plugin.

= How do I clear the browser cache for my WordPress site? =

Turn on **Browser Cache** under Settings → CacheNova → Cache Types. After that, every cache clear changes the version number on your site's own CSS and JavaScript files, so visitors' browsers fetch fresh copies. This works for all visitors without asking them to press Ctrl+F5.

= Does it work with Divi, Elementor, WooCommerce and other themes and plugins? =

Yes. CacheNova hooks into WordPress's own standard events, so it works with any theme and page builder. It also clears the caches of Divi, Elementor, Beaver Builder, Bricks, Oxygen and WooCommerce product transients when they are active.

= Will it conflict with my other caching plugin? =

No. It detects popular caching plugins (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, Autoptimize, WP Fastest Cache, Breeze, WP-Optimize, Cache Enabler, Hummingbird and NitroPack) and clears them as part of a full clear instead of working against them.

= Is Browser Cache safe for logged-in users and WooCommerce? =

Yes. Cache headers are never sent to logged-in users, on WooCommerce cart, checkout or account pages, on search, 404 or preview pages, on password-protected posts, or on non-GET requests.

= Is the automatic database optimization safe? =

It permanently deletes post revisions, trashed posts, spam comments, trashed comments and expired transients, so it is **off by default** and asks you to confirm before it turns on. Take a backup first if you want to keep your revisions.

= Does the background schedule slow my site down? =

No. The scheduled run is a lightweight clear. The heavier full clear only runs when something changes, or when you click Clear Cache Now.

= The Divi et-cache folder was not being removed. Is that fixed? =

Yes. Version 1.1.0 removes the folder reliably, including on Windows and local servers, and checks again at the end of the request in case Divi recreates it. If your server still blocks deleting the folder (for example because of file ownership), the last clear report shows it as failed instead of pretending it worked.

= Can I re-enable Divi's Static CSS File Generation? =

On Divi 4: not while this plugin is active. On Divi 5 the setting no longer exists, so CacheNova simply removes the et-cache folder when you clear the cache.

= What does "Delete all data on uninstall" delete? =

With it ON, deleting the plugin (not just deactivating) removes its settings, reports, schedules and the wp-content/cache/cachenova folder, then runs one final full-site cache clear. It never touches Divi's own et-cache folder. It is OFF by default. You can find it on the Database Optimization tab.

= What is the Persistent Object Cache, and is it safe to turn on? =

It lets WordPress reuse database results across page loads using Redis, Memcached or APCu, or a file-based fallback. It is OFF by default and runs a connection test before installing anything. It never overwrites a different object-cache.php that is already there, and deactivating the plugin removes its drop-in again.

== Screenshots ==

1. CacheNova Settings Access — Find the CacheNova settings page under the WordPress Settings menu to configure cache management and optimization features.
2. CacheNova Dashboard — Manage automatic cache clearing, toast notifications, public/private/object/browser cache types, cache reports, database optimization, persistent object cache, early-bypass page cache, and uninstall data settings from a modern dashboard.

== Changelog ==

= 1.1.0 =
* New: Database Optimization tab with automatic scheduling (every 1 day, 7 days or month) plus manual cleanup.
* New: Clear Cache Now in the admin bar now has a plugin icon, clears without a page reload, and shows a toast message.
* New: Modern confirmation windows replace the old browser alerts.
* New: Browser Cache now really refreshes browsers: your site's own CSS and JS files get a new version after each clear.
* Fixed: Divi et-cache folder not being removed on Clear Cache (Windows-safe paths, read-only files, symlinks, retry and an end-of-request check).
* Fixed: Browser cache headers are no longer sent to logged-in users or WooCommerce cart, checkout and account pages.
* Improved: Clear Cache now also clears Elementor, Beaver Builder, Bricks, Oxygen, WooCommerce transients and more cache plugins and hosts.
* Improved: "Delete all data on uninstall" now appears once, on the Database Optimization tab, and also removes the new schedule and reports.
* Fixed: CacheNova's own object-cache.php and advanced-cache.php drop-ins were wrongly reported as "already installed by someone else" and could be left behind on deactivation. They are now recognised and removed correctly.
* Fixed: The cleaned-items count no longer includes an internal batch number, and changing a setting no longer restarts the auto-clear countdown.

= 1.0.0 =
* Initial release.
