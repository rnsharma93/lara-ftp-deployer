<?php
namespace Ram\Deployer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use ZipArchive;

class DeployController extends Controller
{
    public function run(Request $request)
    {
        try {
            return $this->executeDeployment($request);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function getMetadata(Request $request)
    {
        $provided = $request->header('X-DEPLOY-TOKEN');
        $env = $request->input('env') ?? 'default';
        
        $settings = config("deployer.environments.$env");

        if (!$settings) {
            return response()->json([
                'success' => false,
                'error' => "Invalid environment: $env"
            ], 400);
        }
        
        if (!empty($settings['deploy_token']) && !hash_equals((string)$settings['deploy_token'], (string)$provided)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized - Invalid token'
            ], 401);
        }
        
        // Check both .deploy-meta.json and deploy-meta.json for backwards compatibility
        $metaPath = base_path('.deploy-meta.json');
        if (!file_exists($metaPath)) {
            $metaPath = base_path('deploy-meta.json');
        }
        
        if (!file_exists($metaPath)) {
            return response()->json([
                'exists' => false,
                'environment' => $env
            ]);
        }
        
        $meta = json_decode(file_get_contents($metaPath), true);
        
        return response()->json([
            'exists' => true,
            'environment' => $meta['environment'] ?? null,
            'branch' => $meta['git']['branch'] ?? null,
            'commit_hash' => $meta['git']['commit_hash'] ?? null,
            'deployed_at' => $meta['deployed_at'] ?? null,
            'deployment_type' => $meta['deployment_type'] ?? 'unknown',
            'git' => $meta['git'] ?? null,
            'dependencies' => $meta['dependencies'] ?? null,
            'files' => $meta['files'] ?? null
        ]);
    }

    protected function executeDeployment(Request $request)
    {
        $startTime = microtime(true);
        $logs = [];
        
        $provided = $request->header('X-DEPLOY-TOKEN');
        $env = $request->input('env') ?? 'default';
        $valid = false;

        $settings = config("deployer.environments.$env");

        if (!$settings) {
            return response()->json([
                'success' => false,
                'error' => "Invalid environment: $env"
            ], 400);
        }
        
        if (!empty($settings['deploy_token']) && hash_equals((string)$settings['deploy_token'], (string)$provided)) {
            $valid = true; 
        }
        
        if (!$valid) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized - Invalid token'
            ], 401);
        }

        // Support custom zipname from request
        $zipName = $request->input('zipname') ?? 'deploy.zip';
        $zipPath = base_path($zipName);
        
        // Get commands
        $requestCommands = $request->input('commands');
        $envCommands = $settings['artisan_commands'] ?? null;
        $defaultCommands = config('deployer.default_artisan_commands');
        $commands = $requestCommands ?? $envCommands ?? $defaultCommands;

        $results = [
            'success' => true,
            'environment' => $env,
            'started_at' => now()->toIso8601String(),
            'deletions' => null,
            'auto_deletions' => null,
            'extraction' => null,
            'commands' => [],
            'total_time' => 0
        ];

        // Step 1: Delete manually specified files/folders
        $deleteFiles = $request->input('delete') ?? [];
        if (!empty($deleteFiles)) {
            $results['deletions'] = $this->deleteFiles($deleteFiles);
        }

        // Step 3: Extract main deployment package and auto-detect deleted files
        if (file_exists($zipPath)) {
            // Extract the zip (includes new .deploy-meta.json)
            $results['extraction'] = $this->extractZip($zipPath, $zipName);

            // Read new metadata from extracted files
            $newMetaPath = base_path('.deploy-meta.json');
            $newMeta = file_exists($newMetaPath) 
                ? json_decode(file_get_contents($newMetaPath), true) 
                : null;

            // Delete files that were removed in Git (from metadata)
            if ($newMeta && !empty($newMeta['deleted_files'])) {
                $results['auto_deletions'] = $this->deleteFiles($newMeta['deleted_files']);
            }
        } else {
            $results['extraction'] = [
                'success' => false,
                'message' => "Zip file {$zipName} not found",
                'files_extracted' => 0
            ];
        }

        // Step 3: Run Artisan commands
        foreach ($commands as $cmd) {
            $cmdStart = microtime(true);
            $cmdResult = [
                'command' => $cmd,
                'success' => true,
                'output' => '',
                'error' => null,
                'duration_ms' => 0
            ];
            
            try {
                // Parse command and options from string like "migrate --force" or "queue:work --tries=3"
                $parsed = $this->parseCommand($cmd);
                
                Artisan::call($parsed['command'], $parsed['options']);
                $output = trim(Artisan::output());
                // Strip HTML and limit output
                $output = strip_tags($output);
                // Limit to 500 chars
                if (strlen($output) > 500) {
                    $output = substr($output, 0, 500) . '... (truncated)';
                }
                $cmdResult['output'] = $output;
            } catch (\Exception $e) {
                $cmdResult['success'] = false;
                $cmdResult['error'] = $e->getMessage();
            }
            
            $cmdResult['duration_ms'] = round((microtime(true) - $cmdStart) * 1000);
            $results['commands'][] = $cmdResult;
        }

        $results['total_time'] = round(microtime(true) - $startTime, 2);
        $results['completed_at'] = now()->toIso8601String();

        return response()->json($results);
    }

    /**
     * Delete specified files/folders from the server
     */
    protected function deleteFiles(array $filesToDelete)
    {
        $deleted = [];
        $failed = [];
        $skipped = [];

        foreach ($filesToDelete as $relativePath) {
            // Sanitize path - prevent directory traversal
            $relativePath = ltrim($relativePath, '/');
            if (strpos($relativePath, '..') !== false) {
                $skipped[] = $relativePath . ' (invalid path)';
                continue;
            }

            $fullPath = base_path($relativePath);

            // Don't allow deleting critical files
            $protected = ['.env', 'artisan', 'composer.json', 'composer.lock'];
            if (in_array(basename($relativePath), $protected)) {
                $skipped[] = $relativePath . ' (protected)';
                continue;
            }

            if (!file_exists($fullPath)) {
                $skipped[] = $relativePath . ' (not found)';
                continue;
            }

            try {
                if (is_dir($fullPath)) {
                    $this->deleteDirectory($fullPath);
                    $deleted[] = $relativePath . '/';
                } else {
                    unlink($fullPath);
                    $deleted[] = $relativePath;
                }
            } catch (\Exception $e) {
                $failed[] = $relativePath . ' (' . $e->getMessage() . ')';
            }
        }

        return [
            'success' => empty($failed),
            'deleted' => $deleted,
            'failed' => $failed,
            'skipped' => $skipped,
            'message' => 'Deleted ' . count($deleted) . ' item(s)'
        ];
    }

    /**
     * Recursively delete a directory
     */
    protected function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Extract zip using native extractTo() for maximum speed
     */
    protected function extractZip($zipPath, $label)
    {
        $startTime = microtime(true);
        
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return [
                'success' => false,
                'message' => "Could not open {$label}",
                'files_extracted' => 0
            ];
        }
        
        $totalFiles = $zip->numFiles;
        
        // Single native call - extracts all files at once, overwrites existing
        $success = $zip->extractTo(base_path());
        
        $zip->close();
        @unlink($zipPath);
        
        // Clear opcache after extraction to pick up new files
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        clearstatcache(true);
        
        // Read metadata to determine deployment type
        $metaPath = base_path('.deploy-meta.json');
        $deploymentType = 'full';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            $deploymentType = $meta['deployment_type'] ?? 'full';
        }
        
        return [
            'success' => $success,
            'message' => $success ? ucfirst($deploymentType) . " deployment: {$totalFiles} files" : "Extraction failed",
            'deployment_type' => $deploymentType,
            'files_total' => $totalFiles,
            'files_extracted' => $success ? $totalFiles : 0,
            'errors_count' => $success ? 0 : 1,
            'duration_seconds' => round(microtime(true) - $startTime, 2)
        ];
    }

    /**
     * Parse a command string like "migrate --force" or "queue:work --tries=3"
     * into command name and options array for Artisan::call
     * 
     * Examples:
     *   "migrate --force" => ['command' => 'migrate', 'options' => ['--force' => true]]
     *   "queue:work --tries=3" => ['command' => 'queue:work', 'options' => ['--tries' => 3]]
     *   "optimize:clear" => ['command' => 'optimize:clear', 'options' => []]
     */
    protected function parseCommand($commandString)
    {
        $parts = preg_split('/\s+/', trim($commandString));
        $command = array_shift($parts);
        $options = [];

        foreach ($parts as $part) {
            // Handle --option=value format
            if (preg_match('/^--([^=]+)=(.+)$/', $part, $matches)) {
                $key = '--' . $matches[1];
                $value = $matches[2];
                
                // Try to cast numeric values
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                // Handle boolean strings
                elseif (strtolower($value) === 'true') {
                    $value = true;
                }
                elseif (strtolower($value) === 'false') {
                    $value = false;
                }
                
                $options[$key] = $value;
            }
            // Handle --option format (boolean flag, default true)
            elseif (preg_match('/^--(.+)$/', $part, $matches)) {
                $options['--' . $matches[1]] = true;
            }
            // Handle -o format (short option, default true)
            elseif (preg_match('/^-([a-zA-Z])$/', $part, $matches)) {
                $options['-' . $matches[1]] = true;
            }
            // Handle positional arguments
            else {
                // Positional arguments are indexed numerically
                $options[] = $part;
            }
        }

        return [
            'command' => $command,
            'options' => $options
        ];
    }
}
