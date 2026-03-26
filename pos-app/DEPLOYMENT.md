# Deployment Guide (WAMP POS)

This guide documents a deployment process that matches this repository.

## Scope

- Applies to code under pos-app/
- Uses the migration runner in deploy.php
- Uses maintenance.flag with maintenance.php for maintenance mode responses

## What deploy.php actually does

- Creates a maintenance.flag file at start
- Loads migration files from migrations/*.php
- Runs pending migrations through MigrationManager
- Runs basic health checks
- Removes maintenance.flag at the end (success or failure)

Important: deploy.php does not support CLI flags like --rollback or --maintenance-on.

## Pre-Deployment Checklist

- [ ] Database backup completed
- [ ] Changes validated in staging/local environment
- [ ] New migrations tested against a copy of production data
- [ ] .env / environment variables verified on target host
- [ ] Rollback plan prepared (Git revision + DB restore plan)

## Recommended Deployment Flow

### 1. Put application files on the server

Deploy the new code into the application directory.

Windows example:

```powershell
robocopy . C:\wamp64\www\pos-app /MIR /XD .git
```

### 2. Back up the production database

Windows example:

```powershell
mysqldump -u root -p pos_db > pos_db_backup_YYYYMMDD_HHMMSS.sql
```

Linux example:

```bash
mysqldump -u root -p pos_db > pos_db_backup_YYYYMMDD_HHMMSS.sql
```

### 3. Run migrations

From the project root:

```bash
php deploy.php
```

During execution, users may receive maintenance responses if maintenance.php is checked before route handlers.

### 4. Verify health

- Confirm login works
- Confirm dashboard loads
- Confirm product list and checkout page load
- Confirm recent migration effects (feature toggles, loyalty tables if expected)

### 5. Monitor after release

- Check PHP/Apache error logs
- Check DB error logs
- Validate key cashier/admin workflows

## Rollback Guidance

There is no automated down migration system in this repository.

If deployment fails:

1. Restore previous application code revision.
2. Restore database from backup if migration side effects require it.
3. Remove maintenance.flag if it remains after an interrupted run.

Maintenance file path:

- pos-app/maintenance.flag

## Security and Operations Notes

- Do not store production secrets in tracked files.
- Use environment variables for DB credentials and API keys.
- Restrict access to utility scripts (for example deploy.php and run_sql.php) in production.
- Enforce HTTPS and secure session cookie settings.

## Quick Command Reference

Run migrations:

```bash
php deploy.php
```

Check maintenance flag manually:

```powershell
Get-Item .\maintenance.flag
```

Remove stale maintenance flag manually:

```powershell
Remove-Item .\maintenance.flag -ErrorAction SilentlyContinue
```