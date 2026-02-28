<?php

namespace Ram\Deployer\Console;

use Illuminate\Console\Command;
use Ram\Deployer\Console\Concerns\InteractsWithFtp;

class LaraLogsCommand extends Command
{
    use InteractsWithFtp;

    protected $signature = 'ftp:logs 
                            {--env= : The environment to fetch logs from (default, production, staging)}
                            {--path=laravel.log : Path to the log file relative to storage/logs/}
                            {--tail=100 : Number of lines to show from the end}
                            {--watch : Continuously watch for new log entries}
                            {--download : Download the entire log file locally}';

    protected $description = 'Stream, watch or download log files securely from the remote server via FTP';

    public function handle()
    {
        $env = $this->option('env') ?: 'default';
        $settings = config("deployer.environments.$env");

        if (!$settings) {
            $this->error("❌ Invalid environment: $env");
            return 1;
        }

        $path = $this->option('path');
        // Sanitize path to prevent directory traversal
        $path = str_replace(['..', '\\'], '', $path);
        $remotePath = 'storage/logs/' . ltrim($path, '/');
        
        $size = $this->getFtpFileSize($env, $remotePath);

        if ($size === false || $size === 0) {
            $this->error("❌ Log file not found or empty: {$path} via FTP");
            return 1;
        }

        try {
            // Option A: Download full file safely via FTP
            if ($this->option('download')) {
                return $this->downloadLog($env, $remotePath, $size);
            }

            // Option B: Watch log output continuously via FTP offset polling
            if ($this->option('watch')) {
                $this->info("👀 Watching remote logs for <fg=cyan>{$path}</> via FTP (Press Ctrl+C to stop)...");
                return $this->watchLog($env, $remotePath, $size, $this->option('tail'));
            }

            // Option C: Tail limited lines once via FTP
            return $this->tailLog($env, $remotePath, $size, $this->option('tail'));
        } finally {
            $this->closePersistentFtp();
        }
    }

    protected function tailLog($env, $remotePath, $size, $tail)
    {
        // For tailing over FTP rapidly, we only fetch the last chunk roughly matching lines needed
        $fetchBytes = min($size, $tail * 300); // assume avg 300 chars per line just in case
        $offset = $size - $fetchBytes;
        
        $content = $this->readFtpFile($env, $remotePath, $offset);

        if ($content === false) {
            $this->error("❌ Failed to read log file from FTP.");
            return 1;
        }

        if (empty($content)) {
            $this->line("<fg=gray>Log file has no text payload.</>");
            return 0;
        }

        $lines = explode("\n", rtrim($content));
        if (count($lines) > $tail) {
            $lines = array_slice($lines, -$tail);
        }

        foreach ($lines as $line) {
            $this->renderLine($line);
        }

        return 0;
    }

    protected function watchLog($env, $remotePath, $size, $tail)
    {
        $this->tailLog($env, $remotePath, $size, $tail);
        $offset = $size;

        while (true) {
            sleep(3);
            $newSize = $this->getFtpFileSize($env, $remotePath);

            // New chunks were logged!
            if ($newSize > $offset) {
                $content = $this->readFtpFile($env, $remotePath, $offset);
                if ($content !== false && !empty($content)) {
                    $lines = explode("\n", rtrim($content));
                    foreach ($lines as $line) {
                        $this->renderLine($line);
                    }
                    $offset = $newSize;
                }
            } elseif ($newSize < $offset) {
                // File was probably truncated/cleared (e.g. log rotation)
                $offset = $newSize;
            }
        }
        
        return 0;
    }

    protected function downloadLog($env, $remotePath, $size)
    {
        $formattedSize = $this->formatBytes($size);

        // Warn if file is larger than 5 Megabytes
        if ($size > 5 * 1024 * 1024) { 
            $this->warn("⚠️ The remote log file is large: $formattedSize");
            if (!$this->confirm("Do you want to proceed with downloading it to your local machine via FTP?", false)) {
                $this->line("Download intentionally cancelled.");
                return 0;
            }
        } else {
            $this->info("📥 Downloading remote log file ($formattedSize) via FTP...");
        }

        // Process Save Path (e.g. laravel.log -> laravel-server.log)
        $parsedPath = pathinfo($remotePath);
        $filename = $parsedPath['filename'] . '-server.' . ($parsedPath['extension'] ?? 'log');
        $savePath = storage_path('logs/' . ltrim($filename, '/'));

        // Standard local file system preparation
        if (!is_dir(dirname($savePath))) {
            mkdir(dirname($savePath), 0755, true);
        }

        $success = $this->downloadFromFtp($env, $remotePath, $savePath);

        if ($success) {
            $this->info("✅ File downloaded successfully via FTP!");
            $this->line("📂 Local Path: <fg=cyan>{$savePath}</>");
            return 0;
        }

        $this->error("❌ Failed to download file via FTP.");
        @unlink($savePath);
        return 1;
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function renderLine($line)
    {
        if (str_contains($line, 'ERROR')) {
            $this->line("<fg=red>$line</>");
        } elseif (str_contains($line, 'WARNING')) {
            $this->line("<fg=yellow>$line</>");
        } elseif (str_contains($line, 'INFO')) {
            $this->line("<fg=cyan>$line</>");
        } else {
            $this->line($line);
        }
    }
}
