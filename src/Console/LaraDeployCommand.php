<?php
namespace Ram\Deployer\Console;

use Illuminate\Console\Command;
use Ram\Deployer\Console\Concerns\InteractsWithDeployerApi;
use Ram\Deployer\Console\Concerns\InteractsWithFtp;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LaraDeployCommand extends Command
{
    use InteractsWithDeployerApi, InteractsWithFtp;
    protected $signature = 'ftp:deploy 
        {--env= : Target environment (default, production, staging)}
        {--skip-npm : Skip npm build process}
        {--skip-ftp : Skip FTP upload}
        {--full : Force full deployment (ignore incremental)}
        {--init : First-time deployment (uploads bootstrap file to extract without Laravel)}
        {--zipname=deploy.zip : Name of the deployment zip file}
        {--delete=* : Files/folders to delete on server (relative paths)}';
    
    protected $description = 'Deploy to remote environment with progress tracking';

    protected $startTime;

    public function handle()
    {
        $this->startTime = microtime(true);
        
        $env = $this->option('env') ?: 'default';
        $settings = $this->validateEnvironment($env);
        $isInit = $this->option('init');

        if (!$settings) { 
            return 1; 
        }

        $this->newLine();
        
        if ($isInit) {
            $this->printHeader("🚀 INITIAL DEPLOYMENT TO [{$env}]");
            $this->line("   <fg=yellow>First-time deployment mode - will upload bootstrap file</>");
            $this->newLine();
        } else {
            $this->printHeader("🚀 DEPLOYMENT TO [{$env}]");
        }

        // Step 1: NPM Build
        if (!$this->option('skip-npm') && file_exists(base_path('package.json'))) {
            $this->printStep('NPM Build');
            $this->runTask('npm ci', function() {
                passthru('npm ci 2>&1');
                return true;
            });
            $this->runTask('npm run build', function() {
                passthru('npm run build 2>&1');
                return true;
            });
        }

        // Step 2: Analyze changes
        $this->printStep('Analyzing Changes');
        $serverMeta = null;
        
        // --init forces full deployment (no incremental)
        if ($isInit) {
            $this->line("   <fg=yellow>--init flag: Full deployment for first-time setup</>");
        } elseif (!$this->option('full') && config('deployer.incremental.enabled', true)) {
            $this->line("   Fetching server metadata...");
            $serverMeta = $this->fetchServerMetadata($env);
            
            if ($serverMeta) {
                $this->line("   <fg=green>✓</> Found previous deployment");
                if (isset($serverMeta['deployed_at'])) {
                    $this->line("   Last deployed: <fg=gray>{$serverMeta['deployed_at']}</>");
                }
            } else {
                $this->line("   <fg=yellow>!</> No previous deployment found");
            }
        } else {
            if ($this->option('full')) {
                $this->line("   <fg=yellow>--full flag: Forcing complete deployment</>");
            } else {
                $this->line("   <fg=yellow>Incremental deployment disabled in config</>");
            }
        }

        // Step 3: Detect changes
        $changes = $this->detectChanges($serverMeta);
        
        $this->newLine();
        $this->line("   <fg=yellow>Detection Method:</> {$changes['method']}");
        $this->line("   <fg=yellow>Git Available:</> " . ($changes['git_available'] ? 'Yes' : 'No'));
        
        if (!$changes['is_first_deployment']) {
            $this->line("   <fg=green>Added:</> " . count($changes['added']));
            $this->line("   <fg=yellow>Modified:</> " . count($changes['modified']));
            $this->line("   <fg=red>Deleted:</> " . count($changes['deleted']));
        }

        // Step 4: Create deployment zip
        $zipPath = sys_get_temp_dir() . '/' . $this->option('zipname');
        if (file_exists($zipPath)) unlink($zipPath);

        $this->printStep('Create Deployment Package');
        $this->createDeploymentZip($zipPath, $changes);

        // Step 5: Upload to FTP
        if (!$this->option('skip-ftp')) {
            $this->printStep('FTP Upload');
            if (!$this->uploadDeploymentPackage($zipPath, $env)) {
                return 1;
            }
            
            // For --init, also upload the bootstrap file to public/
            if ($isInit) {
                $this->newLine();
                $this->printStep('Bootstrap File Upload');
                if (!$this->uploadInitBootstrap($env, $this->option('zipname'))) {
                    $this->error('   ❌ Failed to upload init-deploy.php');
                    return 1;
                }
            }
        }

        // Step 6: Remote Deployment
        if ($isInit) {
            // For --init, call the bootstrap endpoint instead
            $this->printStep('Initial Extraction');
            $this->line("   <fg=gray>Calling init-deploy.php to extract files...</>");
            
            $initResponse = $this->callInitEndpoint($env, $this->option('zipname'));
            
            if (!isset($initResponse['success']) || !$initResponse['success']) {
                $this->error('   ❌ ' . ($initResponse['error'] ?? 'Initial extraction failed'));
                if (isset($initResponse['raw'])) {
                    $this->line("   <fg=gray>Response: {$initResponse['raw']}</>");
                }
                $this->newLine();
                $this->printFooter();
                return 1;
            }
            
            $this->line("   <fg=green>✓</> {$initResponse['message']}");
            if (isset($initResponse['files_extracted'])) {
                $this->line("   Files extracted: <fg=cyan>{$initResponse['files_extracted']}</>");
            }
            if (isset($initResponse['duration_seconds'])) {
                $this->line("   Duration: <fg=gray>{$initResponse['duration_seconds']}s</>");
            }
            
            $this->newLine();
            $this->line("   <fg=yellow>Note:</> Artisan commands skipped for initial deployment.");
            $this->line("   <fg=yellow>Next steps:</>");
            $this->line("     1. Configure your .env file on the server");
            $this->line("     2. Run: php artisan ftp:deploy --env={$env}");
            $this->line("        (This will run migrations and other setup commands)");
            
        } else {
            // Regular deployment flow
            $commands = $settings['artisan_commands'] ?? config('deployer.default_artisan_commands');

            $this->printStep('Remote Deployment');
            $this->line("   Endpoint: <fg=cyan>{$this->getDeployerEndpoint($env)}</>");
            $this->newLine();

            // 6a: Extract zip (no commands)
            $this->line("   <fg=gray>Extracting deployment package...</>");
            $extractResponse = $this->callDeployerEndpoint(
                $env, 
                [],
                [
                    'zipname' => $this->option('zipname'),
                    'delete' => $this->option('delete') ?: []
                ]
            );
            $this->displayRemoteResults($extractResponse);

            if (!isset($extractResponse['success']) || !$extractResponse['success']) {
                $this->error('   ❌ Extraction failed, skipping commands');
                $this->newLine();
                $this->printFooter();
                return 1;
            }

            // 6b: Run each command individually
            if (!empty($commands)) {
                $this->newLine();
                $this->line('   <fg=yellow>Artisan Commands:</>');
                
                foreach ($commands as $cmd) {
                    $this->line("   <fg=gray>⏳ Running:</> <fg=white>{$cmd}</>");
                    
                    $cmdResponse = $this->callDeployerEndpoint(
                        $env, 
                        [$cmd],
                        [
                            'zipname' => $this->option('zipname'),
                            'delete' => $this->option('delete') ?: []
                        ]
                    );
                    
                    if (isset($cmdResponse['commands'][0])) {
                        $result = $cmdResponse['commands'][0];
                        $icon = $result['success'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                        $duration = $result['duration_ms'] ?? 0;
                        
                        // Overwrite the "Running" line
                        $this->line("\033[1A\033[2K   {$icon} <fg=white>{$cmd}</> <fg=gray>({$duration}ms)</>");
                        
                        if (!$result['success'] && isset($result['error'])) {
                            $this->line("     <fg=red>Error: {$result['error']}</>");
                        }
                        
                        if (!empty($result['output'])) {
                            $lines = explode("\n", $result['output']);
                            $lineCount = 0;
                            foreach ($lines as $line) {
                                if ($lineCount >= 3) {
                                    $remaining = count($lines) - $lineCount;
                                    $this->line("     <fg=gray>... +{$remaining} more lines</>");
                                    break;
                                }
                                if (trim($line)) {
                                    $this->line("     <fg=gray>{$line}</>");
                                    $lineCount++;
                                }
                            }
                        }
                    } else {
                        $this->line("\033[1A\033[2K   <fg=red>✗</> <fg=white>{$cmd}</> <fg=gray>(failed)</>");
                        if (isset($cmdResponse['error'])) {
                            $this->line("     <fg=red>{$cmdResponse['error']}</>");
                        }
                    }
                }
            }
        }

        // Final summary
        $this->newLine();
        $this->printFooter();
        
        return 0;
    }

    /**
     * Call the deployer endpoint and get JSON response
     */
    /**
     * Create deployment zip with progress bar
     */
    protected function createDeploymentZip($zipPath, $changes)
    {
        $startTime = microtime(true);
        
        // Determine what files to include
        $filesToZip = array_merge(
            $changes['added'] ?? [],
            $changes['modified'] ?? []
        );
        
        // Add vendor if composer dependencies changed
        if ($changes['include_vendor']) {
            $this->line("   <fg=yellow>Including vendor/</> (composer files changed)");
            $filesToZip = array_merge($filesToZip, $this->getVendorFiles());
        }
        
        // Add storage folder exactly as it is for initial deployments
        if ($changes['is_first_deployment'] || $this->option('init')) {
            $this->line("   <fg=yellow>Including storage/</> (initial deployment)");
            $filesToZip = array_merge($filesToZip, $this->getStorageAndCacheFiles());
        }
        
        // node_modules always excluded - npm build runs locally
        
        $totalFiles = count($filesToZip);
        $deployType = $changes['is_first_deployment'] ? 'Full' : 'Incremental';
        
        $this->line("   {$deployType} deployment: <fg=cyan>{$totalFiles}</> files");
        
        if (!empty($changes['deleted'])) {
            $this->line("   Will delete: <fg=red>" . count($changes['deleted']) . "</> files");
        }
        
        $bar = $this->output->createProgressBar($totalFiles);
        $bar->setFormat("   %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%");
        $bar->start();
        
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        
        // Add files to zip
        foreach ($filesToZip as $relPath) {
            $fullPath = base_path($relPath);
            
            if (!file_exists($fullPath)) continue;
            
            if (is_dir($fullPath)) {
                $zip->addEmptyDir($relPath);
            } else {
                $zip->addFile($fullPath, $relPath);
            }
            
            $bar->advance();
        }
        
        // Create metadata
        $metadata = [
            'environment' => $this->option('env') ?: 'default',
            'deployed_at' => now()->toIso8601String(),
            'deployment_type' => $changes['is_first_deployment'] ? 'full' : 'incremental',
            'deployed_files_count' => $totalFiles,
            'deleted_files' => $changes['deleted'] ?? []
        ];
        
        // Add Git info if available
        if ($changes['git_available']) {
            $metadata['git'] = [
                'available' => true,
                'branch' => trim(shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null') ?: 'unknown'),
                'commit_hash' => $changes['git_commit'] ?? trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: ''),
                'previous_commit' => $changes['git_previous_commit'] ?? null
            ];
        } else {
            $metadata['git'] = ['available' => false];
        }
        
        // Add composer dependency hashes
        $metadata['dependencies'] = [
            'composer_json_hash' => file_exists(base_path('composer.json')) 
                ? md5_file(base_path('composer.json')) : null,
            'composer_lock_hash' => file_exists(base_path('composer.lock')) 
                ? md5_file(base_path('composer.lock')) : null,
            'vendor_included' => $changes['include_vendor']
        ];
        
        // Add file hashes (for hash-based method)
        if (isset($changes['current_hashes'])) {
            $metadata['files'] = $changes['current_hashes'];
        }
        
        $zip->addFromString('.deploy-meta.json', json_encode($metadata, JSON_PRETTY_PRINT));
        
        $zip->close();
        $bar->finish();
        
        $elapsed = round(microtime(true) - $startTime, 2);
        $size = $this->formatBytes(filesize($zipPath));
        
        $this->newLine();
        $this->line("   <fg=green>✓</> Created <fg=cyan>{$size}</> in <fg=gray>{$elapsed}s</>");
        $this->line("   <fg=gray>Method: {$changes['method']}, Vendor: " . 
            ($changes['include_vendor'] ? 'Yes' : 'No') . "</>");
    }

    protected function exclude($rel, $list)
    {
        foreach ($list as $rule) {
            if (fnmatch($rule, $rel) || fnmatch($rule . '/*', $rel) || fnmatch('*/' . $rule, $rel) || fnmatch('*/' . $rule . '/*', $rel)) {
                return true;
            }
        }
        return false;
    }

    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    protected function getElapsedTime()
    {
        $elapsed = microtime(true) - $this->startTime;
        if ($elapsed < 60) {
            return round($elapsed, 2) . 's';
        }
        $minutes = floor($elapsed / 60);
        $seconds = round($elapsed % 60);
        return "{$minutes}m {$seconds}s";
    }

    protected function printHeader($title)
    {
        $this->line('<fg=white;bg=blue>                                                    </>');
        $this->line("<fg=white;bg=blue>  {$title}                              </>");
        $this->line('<fg=white;bg=blue>                                                    </>');
        $this->newLine();
    }

    protected function printStep($title)
    {
        $this->newLine();
        $this->line("<fg=yellow>▸</> <fg=white;options=bold>{$title}</>");
        $this->line("  <fg=gray>─────────────────────────────────────</>");
    }

    protected function printFooter()
    {
        $this->line('<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('<fg=green>✅ DEPLOYMENT COMPLETED</>');
        $this->line("   Total time: <fg=cyan>{$this->getElapsedTime()}</>");
        $this->line('<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();
    }

    protected function runTask($title, $callback)
    {
        $start = microtime(true);
        $result = $callback();
        $elapsed = round(microtime(true) - $start, 2);
        if ($result) {
            $this->line("   <fg=green>✓</> {$title} <fg=gray>({$elapsed}s)</>");
        } else {
            $this->line("   <fg=red>✗</> {$title} failed");
        }
        return $result;
    }

    /**
     * Detect changes using Git or file hashing
     */
    protected function detectChanges($serverMeta = null)
    {
        $result = [
            'method' => null,
            'git_available' => false,
            'is_first_deployment' => $serverMeta === null,
            'added' => [],
            'modified' => [],
            'deleted' => [],
            'include_vendor' => false,
            'current_hashes' => []
        ];
        
        // Check if Git is available
        $gitAvailable = $this->isGitAvailable();
        $result['git_available'] = $gitAvailable;
        
        if (!$serverMeta) {
            // First deployment - include everything
            $result['method'] = 'full';
            $result['added'] = $this->getAllTrackedFiles();
            $result['include_vendor'] = true;
            $result['current_hashes'] = $this->generateFileHashes();
            return $result;
        }
        
        if ($gitAvailable && !empty($serverMeta['git']['commit_hash'])) {
            // Use Git method
            $result['method'] = 'git';
            $changes = $this->getGitChanges($serverMeta['git']['commit_hash']);
            $result = array_merge($result, $changes);
        } else {
            // Use file hash method
            $result['method'] = 'hash';
            $changes = $this->getHashBasedChanges($serverMeta['files'] ?? []);
            $result = array_merge($result, $changes);
        }
        
        // Check if composer dependencies changed
        $result['include_vendor'] = $this->shouldIncludeVendor($serverMeta);
        
        return $result;
    }

    /**
     * Check if Git is available
     */
    protected function isGitAvailable()
    {
        $gitCheck = shell_exec('git rev-parse --is-inside-work-tree 2>/dev/null');
        return trim($gitCheck) === 'true' && config('deployer.incremental.git_enabled');
    }

    /**
     * Get changes using Git diff
     */
    protected function getGitChanges($lastCommit)
    {
        $currentCommit = trim(shell_exec('git rev-parse HEAD'));
        $diff = shell_exec("git diff --name-status {$lastCommit} {$currentCommit}");
        
        $added = [];
        $modified = [];
        $deleted = [];
        
        foreach (explode("\n", trim($diff)) as $line) {
            if (empty($line)) continue;
            
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) < 2) continue;
            
            [$status, $file] = $parts;
            
            // Skip excluded paths
            if ($this->shouldExcludeFromTracking($file)) continue;
            
            switch ($status[0]) {
                case 'A': $added[] = $file; break;
                case 'M': $modified[] = $file; break;
                case 'D': $deleted[] = $file; break;
                case 'R':
                    // Renamed file - treat as delete + add
                    if (preg_match('/R\d+/', $status)) {
                        $files = preg_split('/\s+/', $line);
                        if (count($files) >= 3) {
                            if (!$this->shouldExcludeFromTracking($files[1])) {
                                $deleted[] = $files[1];
                            }
                            if (!$this->shouldExcludeFromTracking($files[2])) {
                                $added[] = $files[2];
                            }
                        }
                    }
                    break;
            }
        }
        
        return [
            'added' => $added,
            'modified' => $modified,
            'deleted' => $deleted,
            'git_commit' => $currentCommit,
            'git_previous_commit' => $lastCommit
        ];
    }

    /**
     * Get changes using file hash comparison
     */
    protected function getHashBasedChanges($serverHashes)
    {
        $added = [];
        $modified = [];
        $deleted = [];
        
        $currentFiles = $this->generateFileHashes();
        
        // Find added and modified files
        foreach ($currentFiles as $file => $hash) {
            if (!isset($serverHashes[$file])) {
                $added[] = $file;
            } elseif ($serverHashes[$file] !== $hash) {
                $modified[] = $file;
            }
        }
        
        // Find deleted files
        foreach ($serverHashes as $file => $hash) {
            if (!isset($currentFiles[$file])) {
                $deleted[] = $file;
            }
        }
        
        return [
            'added' => $added,
            'modified' => $modified,
            'deleted' => $deleted,
            'current_hashes' => $currentFiles
        ];
    }

    /**
     * Generate MD5 hashes for all tracked files
     */
    protected function generateFileHashes()
    {
        $hashes = [];
        $fullPath = base_path();
        
        $directory = new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) {
            $relativePath = str_replace(base_path() . '/', '', $current->getPathname());
            if ($current->isDir() && in_array($relativePath, ['vendor', 'node_modules', '.git'])) {
                return false;
            }
            return true;
        });
        
        $it = new RecursiveIteratorIterator($filter);
        
        foreach ($it as $file) {
            if ($file->isDir()) continue;
            
            $relativePath = str_replace(base_path() . '/', '', $file->getPathname());
            
            if ($this->shouldExcludeFromTracking($relativePath)) continue;
            
            $hashes[$relativePath] = md5_file($file->getPathname());
        }
        
        return $hashes;
    }

    /**
     * Check if vendor should be included based on composer file changes
     */
    protected function shouldIncludeVendor($serverMeta)
    {
        if (!file_exists(base_path('composer.json'))) {
            return false;
        }
        
        // No previous metadata or no dependency info - include vendor
        if (!isset($serverMeta['dependencies'])) {
            return true;
        }
        
        $composerJsonHash = md5_file(base_path('composer.json'));
        $composerLockHash = file_exists(base_path('composer.lock')) 
            ? md5_file(base_path('composer.lock')) 
            : null;
        
        // Check if composer.json changed
        if (!isset($serverMeta['dependencies']['composer_json_hash']) ||
            $serverMeta['dependencies']['composer_json_hash'] !== $composerJsonHash) {
            return true;
        }
        
        // Check if composer.lock changed
        if ($composerLockHash && 
            isset($serverMeta['dependencies']['composer_lock_hash']) &&
            $serverMeta['dependencies']['composer_lock_hash'] !== $composerLockHash) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if file should be excluded from tracking
     */
    protected function shouldExcludeFromTracking($file)
    {
        // Fallback defaults in case it's missing from config
        $defaults = [
            '.git', '.env', 'node_modules', 'tests', 'storage/logs', 
            'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'
        ];
        
        $excludePatterns = config('deployer.exclude', $defaults);
        
        // Always ensure vendor is excluded from normal tracking (handled separately)
        if (!in_array('vendor', $excludePatterns) && !in_array('vendor/*', $excludePatterns)) {
            $excludePatterns[] = 'vendor';
        }
        
        return $this->exclude($file, $excludePatterns);
    }

    /**
     * Get all tracked files for initial deployment
     */
    protected function getAllTrackedFiles()
    {
        $files = [];
        $fullPath = base_path();
        
        $directory = new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) {
            $relativePath = str_replace(base_path() . '/', '', $current->getPathname());
            if ($current->isDir() && in_array($relativePath, ['vendor', 'node_modules', '.git'])) {
                return false;
            }
            return true;
        });
        
        $it = new RecursiveIteratorIterator($filter);
        
        foreach ($it as $file) {
            if ($file->isDir()) continue;
            
            $relativePath = str_replace(base_path() . '/', '', $file->getPathname());
            if (!$this->shouldExcludeFromTracking($relativePath)) {
                $files[] = $relativePath;
            }
        }
        
        return $files;
    }

    /**
     * Get all vendor files
     */
    protected function getVendorFiles()
    {
        $files = [];
        $vendorPath = base_path('vendor');
        
        if (!is_dir($vendorPath)) return $files;
        
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendorPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($it as $file) {
            $files[] = str_replace(base_path() . '/', '', $file->getPathname());
        }
        
        return $files;
    }

    /**
     * Get all storage and bootstrap cache files/directories exactly as they are locally
     */
    protected function getStorageAndCacheFiles()
    {
        $files = [];
        $paths = ['storage', 'bootstrap/cache'];
        
        foreach ($paths as $path) {
            $fullPath = base_path($path);
            if (!is_dir($fullPath)) continue;
            
            // Explicitly add the root folder itself
            $files[] = $path;
            
            // SELF_FIRST ensures we include directories (like storage/framework/sessions)
            // even if they are completely empty inside!
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($it as $file) {
                $files[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
        
        return $files;
    }
}
