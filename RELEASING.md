# Releasing CLKT

The GitHub repo and the WordPress.org SVN repo must stay in lockstep. The repo
once drifted a whole feature version behind its own WP.org release (GitHub was
1.0.19 while WP.org shipped 1.1.0) — this checklist prevents that.

## Every release
1. Bump the version in **three** places: `clkt.php` header `Version:`,
   `define('CLKT_VERSION', ...)`, and `readme.txt` `Stable tag:` (+ a changelog entry).
2. Merge to GitHub `main` (CI lint must be green).
3. Publish to WordPress.org SVN (creds: 1Password → "WPORG SVN", user `divydovy`):
   ```
   svn co https://plugins.svn.wordpress.org/clkt-save-forever-collect-together sv
   # copy the release files into sv/trunk (ONLY intended changes — no dev/leftover files)
   svn status sv/trunk        # sanity-check the diff before committing
   svn commit sv/trunk -m "X.Y.Z: ..." --username divydovy --password <op>
   svn copy <base>/trunk <base>/tags/X.Y.Z -m "Tag X.Y.Z" --username divydovy --password <op>
   ```
4. Deploy to getclkt.com: copy the plugin into `clkt-wordpress/wp-content/plugins/
   clkt-save-forever-collect-together/` and push `main` (WordPress.com auto-deploys).
5. Confirm all three agree: GitHub `main`, `api.wordpress.org/.../clkt-....json` version,
   and `getclkt.com/wp-json/clkt/v1/health` version.

## Do NOT
- Ship dev/leftover files to WP.org (keep trunk = the shipped plugin only).
- Let GitHub `main` fall behind the WP.org stable tag.
