<?php

namespace Ram\Deployer\Console\Concerns;

trait InteractsWithFtp
{
    /**
     * Validate FTP configuration for an environment
     */
    protected function validateFtpConfig($env)
    {
        $settings = config("deployer.environments.$env");
        
        $ftpHost = $settings['ftp_host'] ?? null;
        $ftpUser = $settings['ftp_username'] ?? null;
        $ftpPass = $settings['ftp_password'] ?? null;
        
        if (!$ftpHost || !$ftpUser || !$ftpPass) {
            $this->error("❌ FTP credentials missing for $env");
            return null;
        }
        
        $ftpPort = $settings['ftp_port'] ?? 21;
        $ftpPath = rtrim($settings['ftp_path'] ?? '', '/');
        
        return [
            'host' => $ftpHost . ':' . $ftpPort,
            'username' => $ftpUser,
            'password' => $ftpPass,
            'path' => $ftpPath,
        ];
    }

    /**
     * Upload deployment package via FTP for an environment
     */
    protected function uploadDeploymentPackage($zipPath, $env)
    {
        $ftpConfig = $this->validateFtpConfig($env);
        
        if (!$ftpConfig) {
            return false;
        }
        
        return $this->uploadToFtp(
            $zipPath,
            $ftpConfig['host'],
            $ftpConfig['username'],
            $ftpConfig['password'],
            $ftpConfig['path']
        );
    }

    /**
     * Upload file to FTP with progress bar
     */
    protected function uploadToFtp($zipPath, $ftpHost, $ftpUser, $ftpPass, $ftpPath)
    {
        $startTime = microtime(true);
        $fileSize = filesize($zipPath);
        
        $this->line("   Size: <fg=cyan>" . $this->formatBytes($fileSize) . "</>");
        
        $bar = $this->output->createProgressBar(100);
        $bar->setFormat("   %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%");
        $bar->start();
        
        $fp = fopen($zipPath, 'r');
        $url = "ftp://{$ftpHost}/" . ltrim($ftpPath, '/') . '/' . basename($zipPath);
        
        $ch = curl_init($url);
        $lastProgress = 0;
        
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => "$ftpUser:$ftpPass",
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($bar, &$lastProgress, $fileSize) {
                if ($fileSize > 0) {
                    $progress = (int)(($uploaded / $fileSize) * 100);
                    if ($progress > $lastProgress) {
                        $bar->setProgress($progress);
                        $lastProgress = $progress;
                    }
                }
                return 0;
            }
        ]);
        
        curl_exec($ch);
        $bar->finish();
        
        $elapsed = round(microtime(true) - $startTime, 2);
        
        $this->newLine();
        if (curl_errno($ch)) {
            $this->error("   ❌ FTP Error: " . curl_error($ch));
            fclose($fp);
            return false;
        } else {
            $this->line("   <fg=green>✓</> Uploaded in <fg=gray>{$elapsed}s</>");
        }
        
        fclose($fp);
        return true;
    }

    /**
     * Upload init-deploy.php bootstrap file to public/ directory
     */
    protected function uploadInitBootstrap($env, $zipName = 'deploy.zip')
    {
        $ftpConfig = $this->validateFtpConfig($env);
        
        if (!$ftpConfig) {
            return false;
        }

        $token = config("deployer.environments.$env.deploy_token");
        
        if (!$token) {
            $this->error("❌ Deploy token not configured for $env");
            return false;
        }

        // Read the stub file
        $stubPath = __DIR__ . '/../../Stubs/init-deploy.php';
        
        if (!file_exists($stubPath)) {
            $this->error("❌ Init stub file not found: {$stubPath}");
            return false;
        }

        $stubContent = file_get_contents($stubPath);
        
        // Replace placeholders
        $initContent = str_replace(
            ['{{DEPLOY_TOKEN}}', '{{ZIP_NAME}}'],
            [$token, $zipName],
            $stubContent
        );

        // Create temporary file
        $tempFile = sys_get_temp_dir() . '/init-deploy.php';
        file_put_contents($tempFile, $initContent);

        // Upload to public/ subdirectory (cURL will create directory if missing)
        $publicPath = $ftpConfig['path'] . '/public';
        
        $this->line("   Uploading bootstrap file to public/...");
        
        $result = $this->uploadFileToFtp(
            $tempFile,
            $ftpConfig['host'],
            $ftpConfig['username'],
            $ftpConfig['password'],
            $publicPath,
            true  // Create missing directories
        );

        // Cleanup temp file
        @unlink($tempFile);

        return $result;
    }

    /**
     * Upload a single file to FTP (no progress bar, for small files)
     */
    protected function uploadFileToFtp($filePath, $ftpHost, $ftpUser, $ftpPass, $ftpPath, $createMissingDirs = false)
    {
        $fp = fopen($filePath, 'r');
        $url = "ftp://{$ftpHost}/" . ltrim($ftpPath, '/') . '/' . basename($filePath);
        
        $ch = curl_init($url);
        
        $options = [
            CURLOPT_USERPWD => "$ftpUser:$ftpPass",
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => filesize($filePath),
        ];
        
        // Create missing directories if requested
        if ($createMissingDirs) {
            $options[CURLOPT_FTP_CREATE_MISSING_DIRS] = CURLFTP_CREATE_DIR;
        }
        
        curl_setopt_array($ch, $options);
        
        curl_exec($ch);
        
        $success = !curl_errno($ch);
        
        if (!$success) {
            $this->error("   ❌ FTP Error: " . curl_error($ch));
        } else {
            $this->line("   <fg=green>✓</> " . basename($filePath) . " uploaded");
        }
        
        fclose($fp);
        
        return $success;
    }

    protected $persistentCurlHandle = null;

    /**
     * Get the file size of a remote file via FTP using a persistent connection
     */
    protected function getFtpFileSize($env, $remotePath)
    {
        $ftpConfig = $this->validateFtpConfig($env);
        if (!$ftpConfig) return false;

        $url = "ftp://{$ftpConfig['host']}/" . ltrim($ftpConfig['path'], '/') . '/' . ltrim($remotePath, '/');
        
        if (!$this->persistentCurlHandle) {
            $this->persistentCurlHandle = curl_init();
        }
        
        curl_setopt_array($this->persistentCurlHandle, [
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => "{$ftpConfig['username']}:{$ftpConfig['password']}",
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FILETIME => true,
            CURLOPT_FTP_USE_EPSV => false, // Sometimes speeds up persistent connections
            CURLOPT_FORBID_REUSE => false, // Ensure connection pooling is kept alive
            CURLOPT_FRESH_CONNECT => false,
        ]);
        
        curl_exec($this->persistentCurlHandle);
        $size = curl_getinfo($this->persistentCurlHandle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        
        return $size > 0 ? (int)$size : 0;
    }

    /**
     * Download a file from FTP to a local path
     */
    protected function downloadFromFtp($env, $remotePath, $localPath)
    {
        $ftpConfig = $this->validateFtpConfig($env);
        if (!$ftpConfig) return false;

        $url = "ftp://{$ftpConfig['host']}/" . ltrim($ftpConfig['path'], '/') . '/' . ltrim($remotePath, '/');
        
        $fp = fopen($localPath, 'w');
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => "{$ftpConfig['username']}:{$ftpConfig['password']}",
            CURLOPT_FILE => $fp,
        ]);
        
        $success = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        
        return $success !== false;
    }

    /**
     * Read file content via FTP using a persistent connection (supports resuming from offset)
     */
    protected function readFtpFile($env, $remotePath, $offset = 0)
    {
        $ftpConfig = $this->validateFtpConfig($env);
        if (!$ftpConfig) return false;

        $url = "ftp://{$ftpConfig['host']}/" . ltrim($ftpConfig['path'], '/') . '/' . ltrim($remotePath, '/');
        
        if (!$this->persistentCurlHandle) {
            $this->persistentCurlHandle = curl_init();
        }
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => "{$ftpConfig['username']}:{$ftpConfig['password']}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,       // Override NOBODY from getFtpFileSize
            CURLOPT_HEADER => false,       // Override HEADER from getFtpFileSize
            CURLOPT_FORBID_REUSE => false, // Keep alive
            CURLOPT_FRESH_CONNECT => false,
        ];
        
        if ($offset > 0) {
            $options[CURLOPT_RESUME_FROM] = $offset;
        } else {
            $options[CURLOPT_RESUME_FROM] = 0; // Reset offset constraint just in case
        }

        curl_setopt_array($this->persistentCurlHandle, $options);
        
        $content = curl_exec($this->persistentCurlHandle);
        $success = $content !== false;
        
        return $success ? $content : false;
    }

    /**
     * Close the persistent FTP cURL connection gracefully
     */
    protected function closePersistentFtp()
    {
        if ($this->persistentCurlHandle) {
            curl_close($this->persistentCurlHandle);
            $this->persistentCurlHandle = null;
        }
    }
}
