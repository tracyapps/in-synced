=== In Synced ===
Contributors: tracyapps
Tags: staging, sync, development, migration
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push selected database tables, uploads and approved wp-content roots between trusted paired WordPress staging sites.

== Description ==

In Synced provides previews, profiles, post type comparison/repair, progress and a Stop push action for administrator-initiated synchronization. It supports WP-CLI for large transfers. Pair both sites with a long shared secret and enable only the needed receiver permissions.

Version 0.2 requires both paired sites to upgrade together. HMAC v2 signs the nonce, timestamp, method, route and exact body. HTTPS is required by default; redirects are refused. Atomic site leases prevent overlapping send/receive runs. Self-sync and multisite synchronization are refused.

Database replace mode requires a verified SQL dump in private storage outside the public WordPress/content web roots. Every media/file transfer also requires private staging on the destination filesystem, even when database replace is disabled. Partial code never resides in public roots; cross-device copies are refused. Existing schemas must match, and binary values retain their bytes. Users/usermeta and transient options cannot be imported. Generated/foreign-key/partitioned or unusual storage schemas are currently unsupported.

Uploads deny executable extensions, dotfiles and server configuration. Approved wp-content roots can transfer executable code: pairing grants substantial authority and should be used only between sites you fully trust. File chunks are staged, checked and atomically renamed so interrupted transfers preserve existing files. Symlink paths beneath approved roots are refused.

No external service is required. Requests go only to the receiver URL explicitly configured by an administrator. See README.md for configuration, scope changes, security boundaries, backup restoration and development checks.

== Installation ==

1. Install and activate In Synced 0.2.0 on both single-site WordPress installs.
2. Open Tools > In Synced and configure the same random secret (at least 32 characters).
3. Set the sender's final HTTPS receiver URL and public URL replacements.
4. Enable the needed receiver permissions and exact approved wp-content roots.
5. Ensure private storage is writable for database replace and every media/file push. Define IN_SYNCED_BACKUP_DIR with an absolute private directory if the default folder beside WordPress is unavailable. For files, this directory must share the target filesystem.
6. Test the connection, preview the scope and retain a separate host backup before pushing.

== Frequently Asked Questions ==

= Does Stop push roll back a sync? =

No. It requests cancellation between batches/chunks after the current request finishes. Completed changes remain. SQL backups require manual restoration; files need separate backups.

= Where are backups stored? =

By default, in-synced-private-backups/<site-hash>/ beside the WordPress directory; partial file transfers use its private transfers/ subdirectory. The plugin rejects storage within known WordPress/content/plugin/must-use-plugin/theme/uploads roots and the server document root when supplied, including canonical symlink aliases. A WordPress subdirectory install may need IN_SYNCED_BACKUP_DIR outside its parent document root. WP-CLI may omit that document root; operators must keep storage outside every served directory/alias. The private path must share the target filesystem for file transfers. Apache deny files do not protect nginx or IIS. Old uploads/in-synced-backups files are retained and need private relocation or server protection.

= Can I sync independent sites or multisite? =

Sync is intended for single-site staging clones with shared ID lineage. Repair detects unrelated-content row ID collisions, but the tool is not a content merger. Multisite sync is refused; uninstall still cleans plugin options on every network site.

= What does Files mean after upgrading? =

The files scope means approved content roots only. Use media_files for uploads plus roots; theme remains a files alias and content remains a database alias.

= Does uninstall delete backups? =

No, backups remain by default. The in_synced_uninstall_delete_backups filter explicitly opts into deleting completed SQL dumps.

== Changelog ==

= 0.2.0 =
* Nonce-bound v2 protocol, HTTPS, strict acknowledgements and atomic sync sessions.
* Verified private backups, schema/collision checks and lossless database transport.
* Staged atomic files, executable-upload and symlink guards.
* Stop push, accessible scope controls, multisite uninstall and release regression tooling.

= 0.1.0 =
* Initial private development version.

== Upgrade Notice ==

= 0.2.0 =
Upgrade both peers together. V1 signatures are refused. Files now means roots only; use media_files for uploads plus roots. Replace and every media/file push require private storage; file staging must share the destination filesystem.
