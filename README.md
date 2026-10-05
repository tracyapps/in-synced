# In Synced

Reusable WordPress sync tooling for pushing selected local changes to a paired staging or production WordPress install when the host does not provide a good sync path.

## What It Does

- Pairs two WordPress installs with a shared secret and HMAC-signed REST requests.
- Pushes selected database tables in batches.
- Supports table include and exclude lists for partial content syncs.
- Compares local and remote post type counts before a repair push.
- Supports targeted post type repair syncs for interrupted content pushes.
- Rewrites local URLs to remote URLs, including inside serialized WordPress values.
- Transfers uploads/media files in chunks, with include and exclude patterns.
- Transfers explicit `wp-content` file roots such as a theme or custom plugin.
- Saves profiles for repeatable pushes and incremental file syncs.
- Incremental file pushes use the last run that actually pushed files; pass `--all-files` to force a full file pass.
- Previews table counts, row counts, file counts, sizes, and sample paths before pushing.
- Shows admin progress while a push is running.
- Preserves the receiver's `in_synced_*` options during database replacement so pairing survives.
- Creates a randomized SQL backup under `wp-content/uploads/in-synced-backups/` before a replace-mode database push, aborts the push if a restorable backup cannot be written (a dump with no table data counts as a failure), and keeps the newest five backups with web access to the folder blocked.

## Setup

1. Install and activate this plugin on both sites.
2. Open `Tools > In Synced` on both sites.
3. Use the same long shared secret on both sites.
4. On the sending site, set the remote URL and find/replace URLs.
5. On the receiving site, enable only the receiver permissions you want.
6. On the receiving site, list allowed `wp-content` roots, for example:

```text
themes/my-theme
plugins/my-custom-plugin
```

## Partial Sync Controls

Quick scopes:

- `Full`: database, uploads, and configured files.
- `Content + Media`: database and uploads.
- `Database`: database only.
- `Media`: uploads only.
- `Files`: configured `wp-content` roots only.
- `Media + Files`: uploads and configured file roots.

Profile filters:

- `Included table suffixes`: when filled, only these database tables sync.
- `Excluded table suffixes`: removes tables from the selected set.
- `Database scope type`: choose normal selected-table sync or post type repair.
- `Post types for compare/repair`: one post type per line for targeted repair.
- `Upload include patterns`: optional upload-only folder/glob allow list.
- `Upload excludes`: upload folder/glob deny list.
- `wp-content file roots`: explicit relative roots to send.
- `File excludes`: folder/glob deny list for configured roots.

## WP-CLI

```bash
wp in-synced preview --profile=default
wp in-synced compare --profile=default --post-types=book,event
wp in-synced push --profile=default
wp in-synced push --profile=default --scope=database --mode=upsert
wp in-synced push --profile=default --scope=database --mode=replace --post-types=book,event
wp in-synced push --profile=default --scope=theme --all-files
wp in-synced profiles
```

Pass `--path=<wordpress-root>` if your install requires it.

The `theme` scope name is retained for CLI compatibility, but it now means the profile's configured `wp-content` file roots. The `files` scope sends uploads plus those configured roots, and the legacy `content` scope alias is kept for CLI back-compat.

## Repairing Interrupted Content Syncs

If a sync dies halfway through and custom post types look wrong on the remote site:

1. Set `Database scope type` to `Post type repair`.
2. Add the affected post types under `Post types for compare/repair`.
3. Click `Compare Content` to see local vs remote counts.
4. Use `replace` mode with only `Database` checked to overwrite just those post types.

Post type repair deletes matching remote posts for the selected post types, then sends local rows from `posts`, `postmeta`, `comments`, `commentmeta`, and `term_relationships`. Term tables are upserted so taxonomy relationships have matching term records.

## Security Notes

- There is no file browser endpoint.
- Requests must be signed with the shared secret, timestamp, nonce, route, and body hash.
- Nonces are single-use, claimed atomically, and retained for the full allowed timestamp window.
- Upload writes are limited to the WordPress uploads directory.
- Custom file writes are limited to receiver-approved `wp-content` roots.
- Users/usermeta are excluded by default on the sender; the receiver also refuses users/usermeta tables from any signed payload.
- Replace-mode pushes abort when the receiver cannot write a restorable backup first (a dump with no table data counts as a failure), and the backup path is reported in the push log.
- Backups use randomized filenames, the folder receives `.htaccess`/`index.php` web-deny guards when created, and the newest five backups are kept (filter `in_synced_backup_retention` to change).
- Table batches are validated against the receiver's live column list before rows are imported.
- Path checks are lexical; if your host symlinks the uploads directory or an allowed content root, writes follow the symlink (the same behavior as WordPress's own uploads handling).
- The plugin's own `in_synced_*` options (including in-flight nonce rows) are never imported from local to remote, and they survive replace-mode options syncs.

## Backups & Uninstall

- Backups live in `wp-content/uploads/in-synced-backups/` with web access denied. Restore them manually; they are a safety net, not an automated rollback.
- Uninstalling the plugin removes its options, profiles, and transients, but leaves backup files in place by default. Delete them manually, or return `true` from the `in_synced_uninstall_delete_backups` filter before uninstalling to have WordPress remove them.

For stronger staging security, define the shared secret in `wp-config.php` instead of storing it in the database:

```php
define('IN_SYNCED_SHARED_SECRET', 'paste-a-long-random-secret-here');
```

The secret must be at least 32 characters; shorter stored or constant secrets are treated as not paired.
