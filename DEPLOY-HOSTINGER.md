# Deploy Webtrees to Hostinger

This deployment helper updates the Hostinger Webtrees code from the GitHub `main` branch.

## Production layout

- SSH: `u857442812@212.85.9.177`, port `65002`
- Private application directory: `/home/u857442812/applications/webtrees-prod`
- Public web root: `/home/u857442812/domains/lisandromelo.com.br/public_html/familia`
- Backup directory: `/home/u857442812/applications/webtrees-backups`

## One-time local setup

1. Pull the latest `main` branch.
2. Copy the template and edit the private configuration:
   ```bash
   cp deploy-config.example deploy-config
   chmod 600 deploy-config
   nano deploy-config
   ```
3. Fill in the MySQL password. The config file is excluded from Git.
4. Ensure SSH connectivity:
   ```bash
   ssh -p 65002 u857442812@212.85.9.177
   ```

## Simulation

Run from the repository root:

```bash
./deploy.sh
```

Default behavior is a dry-run. It downloads the `main` archive from GitHub and shows rsync's proposed changes. It does not create backups or transfer files.

## Live deployment

Only after reviewing the dry-run output and confirming the production paths:

```bash
./deploy.sh --live
```

Live mode first creates:
- application-file backup (excluding media);
- public-file backup (excluding media);
- compressed MySQL dump.

Then it transfers files with rsync. **No rsync deletion option is used.** The script does not run migrations, alter database records, delete production files, or overwrite the application's `data/` directory. The public media path is excluded from transfer.

## Safety notes

- Keep `deploy-config` private; it contains the database password.
- Confirm the actual production paths before the first live run.
- Review the dry-run carefully.
- The script is not a rollback tool; backups are created before live transfer and should be checked for existence/size.
- After deploy, verify login, trees, media, modules, and visitor links in the production site.
