# Lara FTP Deployer

There are many excellent CI/CD tools available for VPS, cloud, or managed servers. But what about shared hosting where you don't have SSH access or the ability to run commands on the server? In that case, deploying day-to-day Laravel application changes becomes a very difficult and tedious task.

**Lara FTP Deployer** comes to the rescue to solve exactly this issue. You can deploy your changes securely via FTP with a single artisan command. Not only does it upload your files, but this single command can also run your configured set of Artisan commands (from `migrate` to `optimize:clear`) directly on the shared hosting server!

Furthermore, you can instantly stream, watch, and securely download your remote application logs directly from your terminal, treating your shared host like it has native SSH access.

Deploying via FTP doesn't have to be agonizingly slow. This package solves the traditional FTP bottleneck by zipping your application, uploading it as a single chunk, and extracting it blazingly fast directly on your live server using native PHP ZipArchive functions.

It intelligently detects what changed locally and **only deploys the exact modified files** using lightning-fast Git Diff analysis, falling back safely to MD5 hash tracking if Git isn't available.

## Features

- **Blazing Fast Extraction**: Zip files are uploaded and extracted via `ZipArchive::extractTo()`, turning 10-minute file-by-file uploads into seconds.
- **Incremental Deployments**: Analyzes your Git commit history (or local MD5 hashes) to detect additions, modifications, and deletions. Only the exact changes are uploaded to save massive bandwidth.
- **NPM & Vite Integration**: Automatically builds your frontend assets in the local environment and uploads the generated static assets—completely skipping `node_modules`.
- **Composer "Vendor" Smart Tracking**: Ignores thousands of vendor files until `composer.json` or `composer.lock` is specifically modified, at which point it seamlessly bundles the new `vendor` map.
- **Automated Directory Setup**: Sets up required Laravel directories (like `storage/framework/sessions`, `bootstrap/cache`) seamlessly on initial deployment without overwriting live user data.
- **Untracked File Deletion Detection**: Seamlessly detects and deletes files from the remote server if they were uploaded but later locally removed without a Git trace.
- **Remote Artisan Execution**: Instantly run database migrations, cache clearing, and specific commands right from your terminal without SSH (`php artisan ftp:cmd`).
- **Remote Log Streaming**: Seamlessly tail and watch remote log files locally in your terminal with fully color-coded output, no SSH required (`php artisan ftp:logs`).
- **Multi-Environment Support**: Target endless environments via the `--env` flag (e.g. `production`, `staging`, `demo`).

---

## Requirements

- **PHP 8.0+**
- **Laravel 10.x / 11.x**
- Standard FTP connection (Credentials to your cPanel / Plesk or generic server).
- **Remote Endpoint**: The server must be capable of parsing `php` to handle extraction and API calls.

---

## Installation

Install the package into your Laravel project via Composer:

```bash
composer require rnsharma93/lara-ftp-deployer
```

Publish the configuration file (which copies it to `config/deployer.php`):

```bash
php artisan vendor:publish --tag="deployer-config"
```

---

## Configuration

Open `.env` and configure your default target server credentials securely:

```env
# URL where your Laravel app is hosted (e.g. https://example.com)
DEPLOYER_REMOTE_BASE_URL=https://your-domain.com

# Standard FTP configurations pointing to your Laravel Root
DEPLOYER_FTP_HOST=ftp.your-domain.com
DEPLOYER_FTP_USERNAME=your-username
DEPLOYER_FTP_PASSWORD=your-password
DEPLOYER_FTP_PORT=21
DEPLOYER_FTP_PATH=/public_html/

# Secure random token representing the Handshake Secret
DEPLOYER_TOKEN=my-secure-random-deployment-token-12345

# Tracking configuration
DEPLOYER_INCREMENTAL=true
DEPLOYER_INCREMENTAL_GIT=true
```

### Multi-Environment Support

You can define unlimited stages inside `config/deployer.php` (e.g., `production`, `staging`, `testing`). Just define them in the array and use variables like `DEPLOYER_STAGING_FTP_HOST` in your `.env` appropriately.

**Example configuring Staging and Production in `.env`:**
```env
# Production Environment
DEPLOYER_PRODUCTION_REMOTE_BASE_URL=https://production.com
DEPLOYER_PRODUCTION_FTP_HOST=ftp.production.com
DEPLOYER_PRODUCTION_FTP_USERNAME=prod-username
DEPLOYER_PRODUCTION_FTP_PASSWORD=prod-password
DEPLOYER_PRODUCTION_FTP_PATH=/public_html/
DEPLOYER_PRODUCTION_DEPLOY_TOKEN=super-secret-production-token

# Staging Environment
DEPLOYER_STAGING_REMOTE_BASE_URL=https://staging.example.com
DEPLOYER_STAGING_FTP_HOST=ftp.staging.example.com
DEPLOYER_STAGING_FTP_USERNAME=stage-username
DEPLOYER_STAGING_FTP_PASSWORD=stage-password
DEPLOYER_STAGING_FTP_PATH=/staging_html/
DEPLOYER_STAGING_DEPLOY_TOKEN=my-secure-staging-token
```

---

## How it Tracks Changes

The deployer intelligently utilizes two tracking methods to avoid uploading thousands of files:

### 1. Git Diff Tracking (Recommended)
Enabled securely by default if Git is initialized. The system reads the last historically deployed commit hash saved on the live server (`deploy-meta.json`) and compares it against your local `HEAD` commit. **Uncommitted files are safely ignored!** Only strictly committed modifications will deploy.
Enable via `.env`:
`DEPLOY_INCREMENTAL_GIT=true`

### 2. Hash Tracking (Fallback)
If `git_enabled` is set to `false` or Git is entirely missing from your system, the script instantly evaluates your `base_path()`, computes instantaneous MD5 hashes, and evaluates the changes perfectly.

---

## Exclude Files & Folders

There are files that should logically **never** be copied into your production zip package. You can configure what paths to automatically block inside `config/deployer.php`.

```php
'exclude' => [
    '.git',
    '.env',
    '.env.*',
    'node_modules',
    'tests',
    'storage/logs',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    '.idea',
    '.vscode',
],
```

*(Note: Even though the `storage` folders are heavily excluded from tracking scanning for performance, the deployment zip will still gracefully ensure these empty frameworks exist on first deployments!)*

---

## Usage

### 🚀 1. The Initial Deployment

If you are deploying a fresh project to a blank server, use the `--init` flag. 
This bypasses tracking checks, pulls your fully localized config, sets up `storage/` directory definitions, builds your NPM assets, uploads a remote web-extractor, and zips the comprehensive root structure completely.

```bash
php artisan ftp:deploy --init
```

Once extracted, you can jump onto your server to simply define your remote `.env` variables and hook up the Database!

### 🚀 2. Standard Incremental Deployments

For your day-to-day deployments, just run the default command:

```bash
php artisan ftp:deploy
```

**What it does behind the scenes:**
1. Triggers `npm run build` seamlessly.
2. Quickly computes what was locally modified/deleted.
3. Bundles them in a Zip archive weighing a few kilobytes.
4. FTP uploads the tight package.
5. Remotely commands the Laravel server API endpoint to extract the files, perform automatic File Deletions, and run your automated `artisan` server hooks!

### 🌍 3. Target Alternative Environments

Deploy to `staging` environment configured in `config/deployer.php`:

```bash
php artisan ftp:deploy --env=staging
```

---

## Advanced Flags & Controls

Modify your deployment operations using these terminal flags for granular control:

- `--full` : Bypasses incremental change tracking. Force compiles and zips the entire root directory (minus your `exclude` variables).
- `--skip-npm` : Need to just change a PHP controller? Skip the NPM build pipeline dynamically to deploy in 2 seconds.
- `--skip-ftp` : Generates the ZIP and runs the scanner locally for debugging, but intentionally skips the FTP upload and Remote API actions.
- `--zipname=name.zip` : Allows you to customize the deployment payload label.
- `--delete="path"` : Manually issue a server-side deletion of a stale remote file.

Example:
```bash
php artisan ftp:deploy --skip-npm --env=production
```

---

## Remote Commands

Need to clear the cache remotely, optimize, or migrate the server database immediately? You can talk seamlessly to the remote API directly from your terminal.

```bash
php artisan ftp:cmd --cmd="migrate"
php artisan ftp:cmd --cmd="cache:clear" --env=staging
```

> **Note:** The server-side runner gracefully captures terminal streams, so output is perfectly mapped over HTTP back to your local IDE in real-time.

---

## Remote Log Streaming

When bugs happen on a shared host, SSHing in to check `laravel.log` isn't an option. The `ftp:logs` command utilizes lightning-fast pooling to stream remote files directly into your local terminal in beautifully color-coded text.

**1. Tail the Default Log**
Instantly view the last 100 lines of `storage/logs/laravel.log`:
```bash
php artisan ftp:logs
```

**2. Watch the Server Log Live**
Watch the log file continuously. Every 3 seconds it securely queries the FTP server for *new* bytes (it does not download the whole file repeatedly!) and streams it directly to your CLI:
```bash
php artisan ftp:logs --watch
```

**3. Tail Custom Paths**
Specify exactly how many lines you want from other log files relative to `storage/logs/`:
```bash
php artisan ftp:logs --path="worker.log" --tail=50
```

**4. Safely Download Huge Logs**
If a file is extremely large, streaming can be hazardous. The `--download` flag instantly checks the filesize metadata on the server. If it is over **5MB**, it warns you first. It then downloads it securely to your local machine (`storage/logs/laravel-server.log`):
```bash
php artisan ftp:logs --download
```

---

## Customizing Automated Commands

After a zip is successfully extracted on the server, the config sequentially triggers remote hooks immediately. You can define what hits the server after deploying via `config/deployer.php` in the remote environments list:

```php
'artisan_commands' => [
    'down',
    'migrate --force',
    'optimize:clear',
    'config:cache',
    'up',
],
```

Enjoy lightning-fast deployments!

---

## 👨‍💻 Developed By

**Ram Sharma**
- GitHub: [@rnsharma93](https://github.com/rnsharma93)
- Email: rns6393@gmail.com

If you find this package helpful in your daily deployment workflow, please consider starring the repository on GitHub!

---

## 📜 License & Open Source

The **Lara FTP Deployer** package is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).

You are completely free to use, modify, and distribute this package in both personal and commercial projects. Contributions, issues, and feature requests are always welcome! Feel free to check the [issues page](https://github.com/rnsharma93/lara-ftp-deployer/issues) if you want to contribute.
