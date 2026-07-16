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

1. Create a MySQL database and user in cPanel; grant the user all privileges on the DB.
2. Import `db/schema.sql` via cPanel → phpMyAdmin (Import tab) or the CLI.
3. Copy `config.example.php` to `config.php` and fill in DB credentials. **Do not commit `config.php`** (it's gitignored).
4. Point your domain/subdomain's document root at `public/`. If you cannot change the docroot, put the contents of `public/` in `public_html/` and move `src/`, `db/`, `config.php` **outside** the web root (or protect them with `.htaccess`), so only `public/` is web-accessible.
5. Upload sound files into `public/assets/sounds/` (or wherever `config.php`'s `sounds_path` points) — this **must** be under `public/`, since the board/cockpit play them back as `<audio src>` URLs and anything outside the web root 404s.
6. Load the admin cockpit, design a game, set it live, open the board on the TV/projector browser.

## Two-screen setup on game day
- **Admin** opens `public/admin/` on their laptop.
- **Board** opens `public/board/` on the machine driving the TV/projector (full-screen the browser).
- Both must reach the same server. The board follows whatever game is set "live".

## Security notes
- Keep `config.php`, `src/`, and `db/` out of the web root (or deny direct access via `.htaccess`).
- The admin panel should be protected (at minimum HTTP basic auth via cPanel, or a simple login) so the room can't open the cockpit. Auth is not yet implemented — treat it as a required step before public use.
