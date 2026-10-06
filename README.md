# In Synced

Push selected database tables, uploads and explicit wp-content roots between trusted, paired WordPress sites. Intended for staging sites cloned from the same installation. Review a preview, test the receiver and keep a separate host backup before replacing production data.

## Setup

1. Install version 0.2.0 on **both** sites. Version 0.1 signatures are refused; there is no insecure fallback.
2. Open Tools → In Synced. Configure the same random secret of at least 32 characters on both peers, or set `IN_SYNCED_SHARED_SECRET` in wp-config.php. Leave the password field blank to retain a stored secret.
3. On the sender, configure the receiver's final HTTPS URL, local public URL and receiver public URL for replacements. The receiver needs working WordPress REST routing/pretty permalinks. Redirects are refused.
4. On the receiver, enable only the needed database, media and file permissions. Configure exact allowed roots such as `themes/my-theme` or `plugins/my-custom-plugin`. Approving code roots gives the paired secret holder permission to replace executable code; pair only sites you fully trust.
5. For database replace and **every media/file push**, configure writable private storage outside every public web root. The default is `in-synced-private-backups/<site-hash>/` beside the WordPress directory. File staging also requires this directory to share a filesystem with the receiving uploads/code root. Use an absolute path if that parent is unwritable or on another filesystem:

```php
define('IN_SYNCED_SHARED_SECRET', 'a-long-random-secret-of-at-least-32-characters');
define('IN_SYNCED_BACKUP_DIR', '/private/path/wordpress-sync-backups');
```

The plugin rejects storage within the known WordPress, content, plugin, must-use plugin, theme and uploads roots, plus the server's document root when available, including canonical symlink aliases. If WordPress is installed in a subdirectory of the document root, its default sibling storage can still be publicly served and will be refused; configure `IN_SYNCED_BACKUP_DIR` outside that document root. WP-CLI may not provide a document root, so known WordPress roots are still checked and the operator must ensure the custom path is outside every served directory/alias. Apache deny files are defense in depth; nginx and IIS do not honor .htaccess. Old backups under uploads/in-synced-backups are retained; move those outside the web root or deny access in your server configuration.

For isolated LocalWP development only, `define('IN_SYNCED_ALLOW_INSECURE_HTTP', true);` permits an HTTP receiver. HTTP exposes transferred database credentials and file contents; leave this override absent on public servers.

## Scopes and profiles

| Scope | Transfers |
|---|---|
| `full` | Database, uploads and configured roots |
| `content_media` | Database and uploads |
| `database` | Database only |
| `media` | Uploads only |
| `files` | Configured wp-content roots only |
| `media_files` | Uploads and configured roots |

The `theme` alias maps to `files`; `content` maps to `database`. In 0.1 the `files` CLI key included uploads: use `media_files` for that behavior after upgrading.

Profiles support included/excluded table suffixes, post type repair, upload include/exclude patterns, explicit content roots and file excludes. Incremental file sync uses the start time of the last successful run that sent files, so database-only runs and edits during a push do not hide unsent changes. Pass `--all-files` for a full file pass.

```bash
wp in-synced preview --profile=default
wp in-synced compare --profile=default --post-types=book,event
wp in-synced push --profile=default --scope=database --mode=upsert
wp in-synced push --profile=default --scope=database --mode=replace --post-types=book,event
wp in-synced push --profile=default --scope=files --all-files
wp in-synced profiles
```

Use `--path=<wordpress-root>` if needed. WP-CLI is preferable for large transfers because PHP/web-server timeouts can end a browser push.

## Database integrity and repair

Upsert replaces rows by primary/unique keys and does not delete receiver-only rows. Replace clears selected tables after a verified SQL backup; pairing state, nonce claims and active leases remain continuously present during options replacement. User/usermeta tables and transient options are excluded, and receiver guards enforce those boundaries.

A single statement CREATE TABLE allowlist and matching schema fingerprints protect the receiver before replacement. Existing column types, defaults, nullability, indexes and table options must match; auto-increment counters may differ. Generated columns, foreign-key schemas, partition clauses, external storage engines and unusual table options are currently refused. The plugin does not perform schema migrations. Update the receiver's plugins/schema first. Binary strings are base64 encoded on the wire and imported/dumped as hex literals, preserving their bytes. Each request is limited to 8 MB, and batches to 1,000 rows; reduce a profile's batch size if a large row exceeds the limit.

Post type repair preflights incoming row IDs against unrelated receiver content, then backs up and replaces only selected posts and related metadata/comments/relationships. Taxonomy tables are upserted. Use repair only on sites with shared ID lineage: independent installations can also have conflicting taxonomy or plugin-table IDs, and this tool is not a content merger.

Sending and receiving share an atomic site lease to prevent overlapping pushes from truncating each other's source/receiver data. Database requests carry a signed session ID; batches must match prepared tables and repair scope. Leases renew during backup/batches and expire after 15 minutes without activity. An uncertain HTTP failure preserves the receiver lease because its worker might still be running. Wait for expiry before retrying if the receiver cannot finish normally.

Syncing a site to itself and multisite synchronization are refused. Multisite uninstall still removes plugin state from every site.

## File integrity and security

Uploads refuse executable extensions, dotfiles, server configuration and plugin backup paths. Receiver-approved code roots can transfer executable files. Symlink files/directories beneath a configured root are refused, and sender enumeration skips them. Uploads may use a host-managed canonical base directory; writes remain within that resolved base.

Chunks are staged in random `.in-synced-*.part` files in the private storage directory's `transfers/` subdirectory, with directory permissions 0700 and file permissions 0600. Partial PHP source never sits beside a public destination. Contiguous offsets, final size, SHA-256 and path confinement are checked before atomic replacement; existing file permissions are retained, and new files use WordPress's standard file permissions. Both the initial gate and final replacement verify matching filesystem devices. Cross-device copying is refused: configure a private `IN_SYNCED_BACKUP_DIR` on the target filesystem. Files require private staging even with database replace disabled.

Failed or interrupted transfers preserve the existing destination. Old staged files are removed opportunistically after one hour when another file is received. For an inactive receiver, remove old `.in-synced-*.part` files from its private `transfers/` directory manually. The default per-file limit is 256 MB; `in_synced_max_file_size` can adjust it up to a hard 1 GB cap.

HMAC v2 binds method, route, timestamp, nonce and exact request body. Nonces are atomically claimed and retained for the full timestamp acceptance period. The shared secret authorizes powerful database and code changes; keep it private. HTTPS supplies confidentiality. There is no remote file-browser endpoint.

## Stop, backups and restoration

Stop push requests cancellation between HTTP batches/chunks; it waits for the current request. Closing a browser does not reliably stop a PHP worker. A stopped/failed push may have already applied database rows or complete files. **Stop is not rollback.** Read the progress log and restore the receiver backup before retrying if needed.

Replace requires a dump containing every requested table, all counted rows, complete checked writes and a verified read-back checksum. Empty tables produce valid schema backups. The newest five completed backups are retained (`in_synced_backup_retention`); partial dumps are deleted on failure. Dumps contain private database data, including stored credentials, and must remain private. SQL backups are a safety net rather than a full-site snapshot or automatic rollback; take a host backup and pause receiver edits/background jobs during replacement. Row-count checks cannot prove a transactional snapshot when concurrent updates keep the same row count.

Restore manually using your host's database tool or, from the **receiver** install, `wp db import /private/path/to/db-backup.sql` after inspecting the dump and confirming the correct target database. A dump includes DROP TABLE statements for the selected tables. Files need a separate backup. Uninstall deletes options, profiles, leases and transients on every site while retaining backups by default. The `in_synced_uninstall_delete_backups` filter explicitly opts into removing completed SQL backups.

## Development and release checks

```bash
composer install
composer test
composer lint
WP_TESTS_DIR=/path/to/wordpress-tests composer test:integration
```

Integration tests require a disposable WordPress PHPUnit database, never a development or production database. CI checks PHP syntax, WordPress coding standards, PHP 7.2 compatibility and focused safety/integrity regressions. Package using `.distignore` exclusions; vendor dependencies are development-only. Translation source is languages/in-synced.pot. These checks support release review; WordPress directory admission and large-host stress testing remain separate review steps, especially the explicitly approved executable-file transfer feature.
