# Changelog

## 0.2.0 — 2026-10-06

- Require nonce-bound v2 signatures on both peers, HTTPS by default, and no signed-request redirects.
- Add atomic site leases, schema validation before replacement, lossless binary row transport, and strict batch acknowledgements.
- Verify every backup table, row count, write and checksum in private storage before destructive database changes; reject storage beneath the server document root and all known WordPress served roots, including canonical aliases.
- Stage file transfers in verified private storage, require the destination filesystem, and verify size/hash before atomic replacement; refuse executable uploads, dotfiles, server configuration and symlink escapes.
- Add Stop push, keyboard-operable scope radios, visible descriptions and focus indicators.
- Refuse self-sync and multisite sync, detect unrelated-content ID collisions in repair preflight, and retain safe file watermarks.
- Add regression suites, WordPress standards/PHP compatibility tooling, CI and release packaging exclusions.
- Remove plugin state across multisite uninstall while retaining backups by default.

Both paired sites must upgrade to 0.2.0 together. The `files` scope now means configured roots only; `media_files` includes uploads and configured roots. `theme` remains an alias for `files`.
