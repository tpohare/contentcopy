# Content Copy

Push a post, page or custom post type — with its media, terms and custom
fields — from one WordPress site to another, from the post edit screen.

Install the same plugin on both sites. On the source you configure where to
push to; the destination needs no configuration, only a user with an
application password. Repeat pushes update the existing copy instead of
duplicating it, and pushing back the other way is safe: if the destination
copy changed since the last push, the push stops and asks for confirmation
instead of silently overwriting.

## Requirements

- PHP 8.4 or newer, on both sites.
- WordPress 6.0 or newer, on both sites.
- Composer dependencies installed in the plugin directory (see below).
  If `vendor/` is missing, the plugin shows an admin notice and does nothing
  else.

## Install

On **both** sites:

1. Copy this directory to `wp-content/plugins/contentcopy/`.
2. Run `composer install --no-dev` inside it.
3. Activate the plugin.

## Configure

On the **source** site, open Tools → Content Copy:

- **Site URL** — the destination site root, no trailing slash.
- **Username** — a user on the destination site.
- **Application password** — created on the destination under
  Users → Profile → Application Passwords. Spaces can be pasted as-is.
- **Fallback author** — username or email on the destination, used when no
  user there matches the source author's email. Blank means "the user the
  push authenticates as".

Credentials can live in `wp-config.php` instead of the database, which keeps
them out of database exports:

```php
define( 'CONTENTCOPY_DEST_URL', 'https://destination.example' );
define( 'CONTENTCOPY_DEST_USER', 'editor' );
define( 'CONTENTCOPY_DEST_PASSWORD', 'xxxx xxxx xxxx xxxx xxxx xxxx' );
```

When the constants are defined, the settings fields lock and the database
values are ignored.

Press **Test connection** before the first push. It checks authentication,
whether the destination host passes the `Authorization` header through, and
the destination's upload limits.

## Push

Open any post, page or custom post type and use the **Content Copy** panel
(block editor sidebar) or the **Content Copy** metabox (classic editor):

- **Push to destination** sends the item, its media, terms and custom
  fields. The first push creates the copy; later pushes update it.
- If the destination copy was edited since the last push, the button reports
  a conflict and offers **Overwrite destination** instead. Nothing is
  overwritten without that explicit confirmation.

What travels: title, content (raw, unrendered), excerpt, slug, status, date,
menu order, comment/ping status, sticky flag, parent page, terms, author
(matched by email, else the fallback), and **all custom fields**. Protected
plugin state (`_edit_lock`, `_edit_last`, and this plugin's own keys) never
travels.

What happens to media: the featured image, attached files, files embedded in
the content, and files referenced from custom fields are uploaded first and
their URLs rewritten to the destination. Repeats reuse the existing
destination files (matched by identity, then by content hash). Files that no
longer exist on the source are skipped with a warning, and anything that
fails aborts the push before anything is written on the destination.

## Logging

Pushes and imports are recorded to a rotating log file (kept 14 days) under
`wp-content/uploads/contentcopy-logs/`. Filenames contain a per-site secret,
and the directory denies web access, because log lines include post titles,
URLs and field names. Detail level (debug/info/warning/error) and the
on/off switch live on the settings screen. The editor panel shows the
warnings from each push regardless of the file setting.

## How it works

Each item gets a UUID on first save. The destination looks incoming items up
by UUID (custom REST route, authenticated by application password) and
updates the match or creates it. After each push the source stores the
destination copy's content hash and modification time; the next push compares
those before writing, which is the whole conflict system. Media syncs before
the post body, and new posts are written as drafts first and promoted only
after everything else succeeds — a failed push never leaves a half-imported
post behind.

## Troubleshooting

- **"The destination site rejected the credentials"** — wrong username or
  application password, or the destination host strips the `Authorization`
  header. The connection test reports the header status explicitly; on
  Apache the usual fix is passing it through in `.htaccess`.
- **"Did not recognise the Content Copy endpoint"** — the plugin is not
  active on the destination, or a security plugin/firewall is blocking REST.
- **"Larger than the destination upload limit"** — raise the destination's
  `upload_max_filesize`/`post_max_size`, or shrink the file.
- **Post type / taxonomy errors** — register the post type (and taxonomies)
  on the destination first; the push names what is missing.
- **Parent page lands at the top level** — the parent has not been pushed
  yet. Push the parent, then push the child again.
- **A custom field points at the wrong item** — the warning names the field.
  Push the referenced item too, then push this one again.

## Development

```bash
composer install
composer test      # Pest suite (tests/ has WordPress test doubles, no WP install needed)
composer analyse   # PHPStan level 6
composer lint      # php -l over everything
composer cs-fix    # enforce short array syntax
```

Conventions: PHP 8.4 everywhere (`readonly`, enums, first-class callables,
named arguments), strict types, PSR-4 under `ContentCopy\`, short array
syntax only. The push is a small pipeline (`src/Pipeline.php`) carrying a
`PushJob`; the wire format is built by `PushPayload`; per-site identity lives
in `Uuid`. New logic needs a Pest test — see `tests/Unit` for the pattern.
