# Deployment (cPanel shared hosting)

This app is built to run on standard cPanel shared hosting with plain PHP + MySQL. No persistent process, no build step required.

## Before you commit to the host — verify

1. **PHP version ≥ 8.1.** Check in cPanel → "Select PHP Version" (or MultiPHP Manager). 8.1+ preferred.
2. **Frequent AJAX polling is allowed.** The board polls `/api/state.php` every ~1s. On almost all shared hosts this is fine, but confirm:
   - `max_execution_time` is normal (the poll endpoint returns fast, so this only matters if you later try SSE).
   - No aggressive `mod_security` rule blocks repeated same-URL requests. If it does, whitelist the endpoint or slow the poll to ~1.5s.
3. **MySQL database** can be created (cPanel → MySQL Databases). Note the DB name, user, password, host.

> The cPanel account also offers Django / Node / Rails app deployment. We are **not** using it — plain PHP is sufficient and simpler here (see `ARCHITECTURE.md` "Real-time"). If you ever want true WebSocket push, that panel is the escape hatch, but it is out of scope.

## Install

1. Create a MySQL database and user in cPanel; grant the user all privileges on the DB. Prefer a **dedicated** database for this app rather than reusing an existing site's DB — it's a distinct application (own schema, auth, game state) and staying isolated keeps backup/restore and credential scoping simple.
2. Import `db/schema.sql` via cPanel → phpMyAdmin (Import tab) or the CLI.
3. Copy `config.example.php` to `config.php` and fill in DB credentials. **Do not commit `config.php`** (it's gitignored).
4. Deploy to **two separate targets** on the server:
   - **Public target** — the contents of `public/` (board at its root, `admin/` nested underneath). This can be your own docroot, or, if you're hosting Familiada as an unlisted extra under an existing site (e.g. `https://example.com/familiada`, not linked from anywhere, so it's reachable only if someone is told the URL), an otherwise-unlinked subfolder of that site's docroot.
   - **Private target** — `config.php`, `src/`, `db/`. This must live **outside every site's docroot entirely**, not just in an `.htaccess`-denied subfolder underneath one (that's weaker — it depends on Apache config being honored, and a future change to that site could expose it). On typical cPanel accounts, the account home (e.g. `/home/<user>/`) sits one level above the docroot(s) — a sibling folder there, like `/home/<user>/familiada_private/`, is never served by any vhost. Confirm this over SSH/FTP for your specific host before relying on it (see "Verifying the private target" below).
5. Upload sound files into the **public** target's `assets/sounds/` (or wherever `config.php`'s `sounds_path` points) — this **must** be web-accessible, since the board/cockpit play them back as `<audio src>` URLs and anything outside the web root 404s.
6. Load the admin cockpit, design a game, set it live, open the board on the TV/projector browser.

### Automated sync (`deploy/sync.py`)
Steps 2, 4, and 5 (upload files, import schema) are automated by `deploy/sync.py`, which pushes the repo to both targets over one FTP login and imports the schema over SSH. It reads credentials/paths from `deploy.local.env` (gitignored) and uploads **only git-tracked files** (plus `config.php`, which is gitignored but pushed to the private target only).

```bash
python deploy/sync.py --files            # dry-run: print the full public/private upload plan
python deploy/sync.py --files --apply     # upload files over FTP
python deploy/sync.py --db --apply        # import db/schema.sql via SSH (idempotent: CREATE TABLE IF NOT EXISTS)
python deploy/sync.py --all --apply       # both
```
Requirements: Python 3 (uses only stdlib `ftplib`) and an `ssh` client for `--db`. No `lftp`/local `mysql` client needed. The sync is upload-only — it does not delete files removed from the repo, so clean those by hand if you rename/delete tracked paths. Prefer `FTP_PROTOCOL=ftps` in `deploy.local.env` if the host supports it (plain FTP sends credentials in cleartext).

### Private-path resolution (why the app finds `src/` across subtrees)
The public PHP can't reach the private code with a fixed relative path, because on the server public (`websites/owl-it/familiada/`) and private (`~/familiada_private/`) are in different subtrees. Instead, every public entry point requires `public/paths.php` first, which resolves the private directory into the `FAMILIADA_PRIVATE_DIR` constant, in this order:
1. `FAMILIADA_PRIVATE_DIR` from the environment (`$_SERVER`/`getenv`) — e.g. `SetEnv FAMILIADA_PRIVATE_DIR /home/<user>/familiada_private` in `public/.htaccess`.
2. A gitignored `public/private_path.local.php` returning the absolute path. **`deploy/sync.py` writes this automatically** when `SERVER_PRIVATE_ABS_PATH` is set in `deploy.local.env`.
3. Fallback: the parent of `public/` — correct for local dev, where `src/` sits directly above `public/`.

If none resolve to a real private folder, `paths.php` returns a clear HTTP 500 explaining what to set (never an opaque "failed to open stream"). So: set `SERVER_PRIVATE_ABS_PATH` (the **absolute FS path**, not the FTP path — confirm with `echo $HOME` over SSH) and the anchor is handled for you on `--files --apply`.

> **Also set in the server's `config.php`** (it lives in the private dir, so its relative defaults are wrong for the split layout):
> - `sounds_path` → the **absolute** path of the public sounds dir, e.g. `/home/<user>/websites/owl-it/familiada/assets/sounds` (uploads must land under the web root to be playable).
> - `sounds_url_base` → the URL path matching where the board is served, e.g. `/familiada/assets/sounds` (not just `/assets/sounds`, or cues 404 when the app is under a subfolder).

### Hiding it behind an existing site (no visible link)
If Familiada rides along on a site that already exists for other purposes:
- Don't add it to that site's nav, sitemap, or `llms.txt`.
- Add a `Disallow` for its path in that site's `robots.txt` so it isn't indexed if ever discovered.
- The admin/cockpit login (see `src/lib/auth.php`) is the real gate — obscurity alone isn't security, so don't skip it even though the URL is unlinked.

### Verifying the private target
Before trusting a "private" path, confirm read-only that it's actually outside the web-reachable tree:
- Over SSH, `ls -la ~` to find the account home, and inspect what sits alongside the docroot(s) — cPanel accounts often nest multiple sites' docroots under one folder (e.g. `~/websites/<site>/`); a sibling of *that container folder*, not just a sibling of one site inside it, is the safest bet.
- Over FTP, check `pwd` right after login — on most cPanel hosts the FTP session root matches the SSH home, so the same relative paths work for both upload channels.
- Look for a `.htaccess` in the site's docroot to see if `mod_rewrite`/`mod_headers` are already enabled — useful context, though the recommended layout here (board files literally rooted at the public target) doesn't require rewrite rules to work.

## Two-screen setup on game day
- **Admin** opens the public target's `admin/` path on their laptop (e.g. `/familiada/admin`).
- **Board** opens the public target's root on the machine driving the TV/projector (e.g. `/familiada`), full-screen the browser.
- Both must reach the same server. The board follows whatever game is set "live".

## Security notes
- Keep `config.php`, `src/`, and `db/` in the private target, genuinely outside every site's web root — not just `.htaccess`-denied.
- The admin panel is protected by the login gate in `src/lib/auth.php`; don't rely on the URL being unlinked/obscure as the only protection.
