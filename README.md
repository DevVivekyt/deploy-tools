# deploy-tools

Shared GitHub Action that deploys the PHP apps to the cPanel account `geteaseb` on `server.dnsfast.in`.
It uses **no SSH and no FTP**: only the cPanel API (port 2083) and the website over HTTPS.

## What one deploy does

1. Builds a zip of the repo, leaving out `.git`, `.github`, `.env*`, `node_modules`, zips, keys and the app's own `excludes`.
2. Uploads the zip and a one-time runner (`_deploy-<random>.php`) through the cPanel API.
3. Calls the runner once over HTTPS with a one-time token. The runner then:
   1. **makes a full database backup** at `storage/backups/predeploy-<date>.sql.gz` (it keeps the last 10). If the backup fails, the deploy stops and nothing is changed.
   2. copies the new files over the old ones. **Nothing on the server is deleted.** No `.env` is ever replaced, so uploads, logs, sessions and settings stay as they are.
   3. runs the app's `db_commands` with the PHP command line, for example `database/migrate.php` or `artisan migrate --force`.
4. Deletes the runner and the zip. The runner also refuses to work after 30 minutes and deletes itself.

**To restore a backup:** cPanel → phpMyAdmin → select the database → Import → the `predeploy-….sql.gz` file.

## Use in an app

```yaml
      - uses: actions/checkout@v5
      - uses: DevVivekyt/deploy-tools@main
        with:
          cpanel_token: ${{ secrets.CPANEL_TOKEN }}
          site_url: https://accounts.geteasebill.xyz
          deploy_path: /home/geteaseb/accounts.geteasebill.xyz
          php_bin: ${{ vars.PHP_BIN }}
          db_commands: |
            database/migrate.php
            database/post_deploy.php
```

| input | default | |
|---|---|---|
| `runner_folder` | `public` | Folder inside `deploy_path` that the site serves at `<site_url>/public/`. Use `''` if the app has no `public/` folder. |
| `source` | `.` | Upload only this folder of the repo, e.g. `upload` |
| `excludes` | | Extra rsync patterns, one per line |
| `env_file` | `.env` | Where the `DB_*` settings live on the server (`DB_DATABASE`/`DB_USERNAME` or `DB_NAME`/`DB_USER`) |
| `backup_dir` | `storage/backups` | |
| `lint` | `true` | `php -l` on all PHP files first |

## If something fails

| message | fix |
|---|---|
| cPanel refused the API token | Create a new token in cPanel → Security → Manage API Tokens and save it as `CPANEL_TOKEN` |
| upload failed / folder does not exist | Check `deploy_path`, and that `public/` exists there |
| `…_deploy-….php was not found` | The site does not serve `public/` at `<site_url>/public/`. Set `runner_folder` |
| needs the PHP command line | Add the repo variable `PHP_BIN`, e.g. `/usr/local/bin/ea-php83` (ask the host for the path) |
