=== SiteMover ===
Contributors: rolandasb
Tags: migration, backup, clone, move, import export
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress site to a ZIP and import it on any server — database, files, and automatic URL replacement included. Free up to 2 GB.

== Description ==

**SiteMover** is the simplest way to move, clone, or migrate a WordPress site. One click to export, one upload to import — no FTP, no phpMyAdmin, no command line.

Export your entire site (database + `wp-content`) to a single ZIP file directly from the WordPress admin. Upload that ZIP to a new server running SiteMover and the plugin will restore the database, copy all files, and automatically rewrite every URL — including serialised PHP data — to match the new domain.

= Key Features =

* **Full-site export** — database SQL dump and complete `wp-content` folder packed into one ZIP
* **Selective export** — export database only or files only when you need a lighter archive
* **Chunked file processing** — large sites are processed in batches so exports never time out, even on shared hosting
* **Automatic URL replacement** — old domain is rewritten to new domain everywhere in the database
* **Serialisation-aware search & replace** — safely updates PHP serialised strings without corrupting data
* **Table prefix rewriting** — works correctly when source and destination use different database prefixes
* **Drag-and-drop import** — drop a SiteMover ZIP onto the import panel and click Import
* **Real-time progress bar** — live percentage and ETA during export
* **Zero external dependencies** — uses only WordPress core and standard PHP extensions
* **Secure exports** — exported ZIPs are stored in the uploads folder, protected by `.htaccess`, and deleted from the server immediately after download

= Free Plan Limits =

The free version supports sites with a `wp-content` folder up to **2 GB**. Sites larger than 2 GB require SiteMover Pro.

= Typical Use Cases =

* Moving a site from localhost / staging to production
* Cloning a live site to a staging environment
* Changing hosting providers
* Backing up a site as a portable ZIP

= How It Works =

**Export**

1. Go to **Tools → SiteMover** on the source site.
2. Choose the export scope: *Full site*, *Database only*, or *Files only*.
3. Click **Export to ZIP** and watch the progress bar.
4. Download the ZIP when it finishes (it is automatically removed from the server after download).

**Import**

1. Install and activate SiteMover on the destination site.
2. Go to **Tools → SiteMover**.
3. Drop the ZIP onto the import panel or click *Browse* to select it.
4. Click **Import from ZIP** and confirm the warning.
5. The plugin imports the database, rewrites all URLs, and restores files. Done.

= Requirements =

* WordPress 5.9 or higher
* PHP 7.4 or higher
* PHP `ZipArchive` extension (enabled by default on virtually all hosts)
* Administrator account (`manage_options` capability)

== Installation ==

= Automatic (recommended) =

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **SiteMover**.
3. Click **Install Now**, then **Activate**.
4. Navigate to **Tools → SiteMover** to start using the plugin.

= Manual =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP and click **Install Now**, then **Activate**.

= After installation =

No configuration is required. Open **Tools → SiteMover**, choose Export or Import, and follow the on-screen instructions.

== Frequently Asked Questions ==

= Do I need to install SiteMover on both sites? =

Yes. SiteMover must be active on the **source** site to create the export ZIP and on the **destination** site to import it.

= Will my URLs be updated automatically? =

Yes. When you import a SiteMover ZIP, the plugin detects the old site URL stored in the archive and replaces every occurrence with the new site's URL. This includes standard database columns as well as PHP serialised data (such as widget settings, theme customiser data, and many plugin options).

= My site is larger than 2 GB. What should I do? =

The free version supports exports up to 2 GB (`wp-content` folder size). Upgrade to **SiteMover Pro** to remove this limit and migrate sites of any size.

= Does the export include all plugins and themes? =

Yes. The *Full site* and *Files only* export modes include everything inside `wp-content` — plugins, themes, and uploads — except the cache folder and previously generated SiteMover ZIPs (those are always excluded to keep the archive clean).

= Does the import overwrite my existing database? =

Yes. Importing replaces the current database tables with those from the archive. **Always create a backup before importing.** The plugin will ask you to confirm before proceeding.

= The export times out on my shared host. What can I do? =

SiteMover processes files in batches (50 files per request) to avoid PHP execution time limits. If timeouts still occur, ensure your host allows at least **60 seconds** of PHP execution time (`max_execution_time`). Increasing `memory_limit` to 256 MB or higher also helps on very large sites.

= Does SiteMover support Multisite? =

The current version is designed for single-site installations. Multisite support is planned for a future release.

= What PHP extensions are required? =

Only the `ZipArchive` extension, which is included with PHP and enabled by default on virtually all shared, VPS, and managed WordPress hosts.

= Where are exported ZIPs stored? =

ZIPs are saved to `wp-content/uploads/sitemover-exports/`. The directory is protected by an `.htaccess` deny-all rule and an empty `index.php` so files cannot be accessed directly. The file is deleted from the server as soon as you download it.

= Can I export only the database or only the files? =

Yes. Use the export scope selector to choose *Database only* (SQL dump) or *Files only* (`wp-content` folder).

= Is it safe to use on a live production site? =

Export is safe and read-only — it never modifies any data. For import, the plugin overwrites the database, so **always back up first** and prefer importing to a staging environment before touching production.

== Screenshots ==

1. **Export panel** — choose export scope and start the export with one click.
2. **Real-time progress** — live progress bar and ETA while the ZIP is being built.
3. **Import panel** — drag-and-drop ZIP upload with automatic URL replacement.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release of SiteMover.
