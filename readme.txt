=== In Synced ===
Contributors: tapps
Tags: sync, staging, migration, database, deployment
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely push selected database rows, uploads, and wp-content files between paired WordPress sites.

== Description ==

In Synced pairs two WordPress installs with a shared secret and pushes selected local changes to the remote site over HMAC-signed REST requests. It is built for staging and production workflows where the host does not provide a good sync path.

Features:

* Pair two sites with a shared secret (32+ characters, ideally defined via `IN_SYNCED_SHARED_SECRET` in wp-config.php).
* Push selected database tables in batches, in upsert or replace mode.
* Include/exclude table suffix lists for partial content syncs.
* Compare local and remote post type counts before a repair push.
* Targeted post type repair for interrupted content pushes.
* URL find/replace, including inside serialized values.
* Uploads/media transfer in chunks, with include/exclude patterns.
* Explicit wp-content file root transfer (for example a theme or a custom plugin).
* Saved profiles, incremental file syncs, previews, and admin progress.
* Replace-mode pushes create a randomized SQL backup first and abort if a restorable backup cannot be written (a dump with no table data counts as a failure).

== Installation ==

1. Install and activate the plugin on both sites.
2. Open Tools > In Synced on both sites.
3. Use the same long shared secret on both sites.
4. On the sending site, set the remote admin/API URL and the find/replace URLs.
5. On the receiving site, enable only the receiver permissions you want.
6. On the receiving site, list allowed wp-content roots, for example `themes/my-theme` or `plugins/my-custom-plugin`.

== Frequently Asked Questions ==

= Where are the database backups stored? =

Replace-mode pushes write a SQL dump to `wp-content/uploads/in-synced-backups/` before clearing tables. Files use randomized names (`db-YYYYmmdd-HHMMSS-<random>.sql`), the folder is protected against web access with `.htaccess` and `index.php` guards, and the newest five backups are kept (change with the `in_synced_backup_retention` filter). Restoring a backup is a manual operation.

= What happens if the receiver cannot write a backup? =

The replace-mode push is aborted before any table is cleared. A backup that would contain no table data also counts as a failure, so an empty table manifest cannot bypass the check.

= How do incremental file pushes work? =

File incrementals send uploads/wp-content files modified since the last run that actually pushed files. To force a full pass, push with `--all-files` (WP-CLI) or disable Incremental files in the profile.

= Are user tables synced? =

No. Users and usermeta are excluded on the sender by default, and the receiver refuses users/usermeta tables from any signed payload.

= What happens on uninstall? =

Uninstalling removes the plugin's options, profiles, and transients. Backup files under `uploads/in-synced-backups/` are left in place by default; return `true` from the `in_synced_uninstall_delete_backups` filter before uninstalling to remove them too.

= Why does a request fail with "Invalid signature" or "Timestamp outside the allowed window"? =

Signed requests must match the same shared secret on both sites, and server clocks must be within five minutes of each other.

== Screenshots ==

== Changelog ==

= 0.1.0 =
* Initial release.
* Hardening pass: randomized, web-blocked, retained backups; replace mode aborts when a backup cannot be written; atomic single-use nonces; receiver refuses users/usermeta; table column validation; i18n baseline; admin accessibility and safety UX; uninstall cleanup.
