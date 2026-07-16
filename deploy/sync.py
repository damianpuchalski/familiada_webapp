#!/usr/bin/env python3
"""
Familiada — deploy sync: push the local git repo to the live server.

Two independent actions (default is a safe dry-run for both):

  python deploy/sync.py --files            # dry-run: list what would upload
  python deploy/sync.py --files --apply     # upload files over FTP
  python deploy/sync.py --db --apply        # import db/schema.sql via SSH
  python deploy/sync.py --all --apply       # both

Design / safety notes
---------------------
* Uploads ONLY git-tracked files (`git ls-files`), so local junk, the .git
  dir, and gitignored secrets can never be pushed by accident.
* `config.php` is the ONE deliberate exception: it is gitignored (holds DB
  creds + auth hash) but is uploaded to the PRIVATE target only, never public.
* Two FTP targets, both under the one FTP login (session root == account home):
    - public  -> FTP_REMOTE_PATH_PUBLIC   (contents of public/, web-reachable)
    - private -> FTP_REMOTE_PATH_PRIVATE  (config.php + src/ + db/, NOT served)
* Upload-only: stale files already on the server are NOT deleted. Remove those
  by hand if you rename/delete tracked files (rare).
* DB import pipes db/schema.sql into the server's own mysql over SSH, so no
  local mysql client and no tunnel are needed. schema.sql uses
  CREATE TABLE IF NOT EXISTS, so re-running is non-destructive to existing rows.

Config is read from deploy.local.env at the repo root (gitignored).
"""

import argparse
import ftplib
import io
import posixpath
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
ENV_PATH = REPO / "deploy.local.env"


def load_env(path: Path) -> dict:
    if not path.is_file():
        sys.exit(f"ERROR: {path} not found. Copy the template and fill it in.")
    env = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, val = line.split("=", 1)
        env[key.strip()] = val.strip()
    return env


def git_tracked(subdir: str) -> list[str]:
    """Repo-relative paths (posix, forward slashes) of git-tracked files under subdir."""
    result = subprocess.run(
        ["git", "-C", str(REPO), "ls-files", subdir],
        capture_output=True, text=True, check=True,
    )
    return [line for line in result.stdout.splitlines() if line]


def build_manifest(env: dict) -> list[tuple[str, str]]:
    """List of (local_relpath, remote_path) pairs."""
    pub = env["FTP_REMOTE_PATH_PUBLIC"].rstrip("/")
    priv = env["FTP_REMOTE_PATH_PRIVATE"].rstrip("/")
    manifest: list[tuple[str, str]] = []

    # Public target gets the CONTENTS of public/ (strip the leading "public/").
    for f in git_tracked("public"):
        rel = f[len("public/"):]
        manifest.append((f, f"{pub}/{rel}"))

    # Private target keeps src/ and db/ as top-level folders.
    for top in ("src", "db"):
        for f in git_tracked(top):
            manifest.append((f, f"{priv}/{f}"))

    # config.php: gitignored, pushed to PRIVATE only.
    if (REPO / "config.php").is_file():
        manifest.append(("config.php", f"{priv}/config.php"))
    else:
        print("WARNING: config.php not found locally — the private target will "
              "have no DB credentials. Create it from config.example.php first.",
              file=sys.stderr)

    return manifest


def connect_ftp(env: dict) -> ftplib.FTP:
    host = env["FTP_HOST"]
    port = int(env.get("FTP_PORT", "21"))
    user = env["FTP_USER"]
    password = env["FTP_PASS"]
    proto = env.get("FTP_PROTOCOL", "ftp").lower()

    if proto == "ftps":
        ftp = ftplib.FTP_TLS()
        ftp.connect(host, port, timeout=30)
        ftp.login(user, password)
        ftp.prot_p()  # encrypt the data channel too
    else:
        print("WARNING: plain FTP — the password and files travel in cleartext. "
              "Set FTP_PROTOCOL=ftps in deploy.local.env if the host supports it.")
        ftp = ftplib.FTP()
        ftp.connect(host, port, timeout=30)
        ftp.login(user, password)
    return ftp


def ensure_remote_dir(ftp: ftplib.FTP, remote_dir: str, made: set) -> None:
    """Create remote_dir and every parent, idempotently."""
    cur = ""
    for part in remote_dir.strip("/").split("/"):
        cur = f"{cur}/{part}" if cur else part
        if cur in made:
            continue
        try:
            ftp.mkd(cur)
        except ftplib.error_perm as exc:
            # 550 == already exists (or no-op); anything else is a real error.
            if not str(exc).startswith("550"):
                raise
        made.add(cur)


def private_pointer(env: dict) -> tuple[str, bytes] | None:
    """
    (remote_path, content) for public/private_path.local.php — the anchor that
    tells the public PHP where the private code lives (absolute server path).
    Returns None if SERVER_PRIVATE_ABS_PATH is not set (then the app must get
    the path some other way: .htaccess SetEnv, or a hand-placed pointer).
    See public/paths.php for how this is consumed.
    """
    abs_path = env.get("SERVER_PRIVATE_ABS_PATH", "").strip().rstrip("/")
    if not abs_path:
        return None
    pub = env["FTP_REMOTE_PATH_PUBLIC"].rstrip("/")
    safe = abs_path.replace("\\", "/").replace("'", "\\'")
    content = f"<?php\n// Auto-generated by deploy/sync.py — do not edit by hand.\nreturn '{safe}';\n"
    return f"{pub}/private_path.local.php", content.encode("utf-8")


def sync_files(env: dict, apply: bool) -> None:
    manifest = build_manifest(env)
    pointer = private_pointer(env)
    mode = "APPLY" if apply else "DRY-RUN"
    print(f"\n=== File sync ({mode}) — {len(manifest)} files ===")

    if not apply:
        for local, remote in manifest:
            print(f"  {local}  ->  {remote}")
        if pointer:
            print(f"  (generated)  ->  {pointer[0]}   [private-path anchor]")
        else:
            print("  NOTE: SERVER_PRIVATE_ABS_PATH not set — no private-path anchor will be "
                  "written. The app will 500 unless the path is supplied via .htaccess "
                  "SetEnv or a hand-placed public/private_path.local.php.")
        print("\nDry-run only. Re-run with --apply to upload.")
        return

    ftp = connect_ftp(env)
    made: set = set()
    uploaded = 0
    try:
        for local, remote in manifest:
            ensure_remote_dir(ftp, posixpath.dirname(remote), made)
            with open(REPO / local, "rb") as fh:
                ftp.storbinary(f"STOR {remote}", fh)
            uploaded += 1
            print(f"  [{uploaded}/{len(manifest)}] {remote}")
        if pointer:
            remote, content = pointer
            ensure_remote_dir(ftp, posixpath.dirname(remote), made)
            ftp.storbinary(f"STOR {remote}", io.BytesIO(content))
            print(f"  [anchor] {remote}")
    finally:
        ftp.quit()
    extra = " + private-path anchor" if pointer else ""
    print(f"\nUploaded {uploaded} files{extra}.")
    if not pointer:
        print("WARNING: no SERVER_PRIVATE_ABS_PATH set — the app will 500 until the "
              "private path is supplied (see docs/DEPLOYMENT.md).")


def import_db(env: dict, apply: bool) -> None:
    schema = REPO / "db" / "schema.sql"
    if not schema.is_file():
        sys.exit(f"ERROR: {schema} not found.")

    ssh_cmd = ["ssh", "-p", str(env.get("SSH_PORT", "22"))]
    key = env.get("SSH_KEY_PATH", "").strip()
    if key:
        ssh_cmd += ["-i", key]
    remote_mysql = f"mysql -u {env['DB_USER']} -p{env['DB_PASS']} {env['DB_NAME']}"
    ssh_cmd += [f"{env['SSH_USER']}@{env['SSH_HOST']}", remote_mysql]

    mode = "APPLY" if apply else "DRY-RUN"
    print(f"\n=== DB import ({mode}) ===")
    print(f"  {' '.join(ssh_cmd)}  <  {schema.relative_to(REPO)}")

    if not apply:
        print("\nDry-run only. Re-run with --apply --db to execute.")
        print("You will be prompted for the SSH password unless SSH_KEY_PATH is set.")
        return

    with open(schema, "rb") as fh:
        subprocess.run(ssh_cmd, stdin=fh, check=True)
    print("\nSchema imported.")


def main() -> None:
    ap = argparse.ArgumentParser(description="Sync local git repo to the live server.")
    ap.add_argument("--files", action="store_true", help="sync files over FTP")
    ap.add_argument("--db", action="store_true", help="import db/schema.sql over SSH")
    ap.add_argument("--all", action="store_true", help="do both --files and --db")
    ap.add_argument("--apply", action="store_true",
                    help="actually perform actions (without this, dry-run)")
    args = ap.parse_args()

    if not (args.files or args.db or args.all):
        args.files = True  # default action

    env = load_env(ENV_PATH)

    if args.files or args.all:
        sync_files(env, args.apply)
    if args.db or args.all:
        import_db(env, args.apply)


if __name__ == "__main__":
    main()
