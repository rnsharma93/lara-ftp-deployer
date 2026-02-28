<?php

namespace Ram\Deployer\Console\Concerns;

use Illuminate\Support\Facades\Http;

trait InteractsWithDeployerApi
{
    /**
     * Get the deployer endpoint URL for an environment
     */
    protected function getDeployerEndpoint($env, $path = '/deployer/run')
    {
        $remoteUrl = config("deployer.environments.$env.remote_base_url");
        return rtrim($remoteUrl, '/') . $path;
    }

    /**
     * Call the deployer endpoint and get JSON response
     */
    protected function callDeployerEndpoint($env, $commands, $additionalData = [])
    {
        $token = config("deployer.environments.$env.deploy_token");
        $endpoint = $this->getDeployerEndpoint($env);
        
        if (!$token) {
            return [
                'success' => false,
                'error' => "Deploy token not configured for environment: {$env}"
            ];
        }
        
        try {
            $response = Http::timeout(300)
                ->withHeaders([
                    'X-DEPLOY-TOKEN' => $token,
                    'Accept' => 'application/json',
                ])
                ->post($endpoint, array_merge([
                    'env' => $env,
                    'commands' => $commands,
                ], $additionalData));

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "Server returned HTTP {$response->status()}",
                    'raw' => substr($response->body(), 0, 200)
                ];
            }

            return $response->json();
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'error' => "Connection Error: {$e->getMessage()}"
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Request Error: {$e->getMessage()}"
            ];
        }
    }

    /**
     * Fetch deployment metadata from server
     */
    protected function fetchServerMetadata($env)
    {
        $token = config("deployer.environments.$env.deploy_token");
        $endpoint = $this->getDeployerEndpoint($env, '/deployer/metadata');
        
        if (!$token) {
            $this->line("   <fg=red>Deploy token not configured for environment: {$env}</>");
            return null;
        }
        
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-DEPLOY-TOKEN' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($endpoint, ['env' => $env]);

            if (!$response->successful()) {
                $this->line("   <fg=red>HTTP {$response->status()}:</> {$response->body()}");
                return null;
            }

            $data = $response->json();
            
            if (!$data || !isset($data['exists']) || !$data['exists']) {
                return null;
            }
            
            return $data;
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->line("   <fg=red>Connection Error:</> {$e->getMessage()}");
            return null;
        } catch (\Exception $e) {
            $this->line("   <fg=red>Error:</> {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Validate environment configuration
     */
    protected function validateEnvironment($env)
    {
        $settings = config("deployer.environments.$env");

        if (!$settings) {
            $this->error("❌ Invalid environment: $env");
            return null;
        }

        $remote = $settings['remote_base_url'] ?? null;
        $token = $settings['deploy_token'] ?? null;

        if (!$remote) {
            $this->error("❌ remote_base_url missing for $env");
            return null;
        }

        if (!$token) {
            $this->error("❌ deploy_token missing for $env");
            return null;
        }

        return $settings;
    }

    /**
     * Display remote deployment results (extraction & deletions)
     */
    protected function displayRemoteResults($response)
    {
        if (!isset($response['success'])) {
            $this->error('   ❌ Invalid response from server');
            return;
        }

        if (!$response['success']) {
            $this->error('   ❌ ' . ($response['error'] ?? 'Unknown error'));
            if (isset($response['hint'])) {
                $this->warn('   💡 ' . $response['hint']);
            }
            if (isset($response['raw']) && $response['raw']) {
                $this->line('   <fg=gray>Response: ' . substr($response['raw'], 0, 200) . '</>');
            }
            return;
        }

        // Deletion results
        if (isset($response['deletions']) && $response['deletions']) {
            $del = $response['deletions'];
            $icon = $del['success'] ? '✓' : '⚠';
            $this->line("   <fg=yellow>Deletions:</> {$icon} {$del['message']}");
            foreach ($del['deleted'] ?? [] as $file) {
                $this->line("     <fg=red>-</> <fg=gray>{$file}</>");
            }
        }

        // Extraction results
        if (isset($response['extraction']) && $response['extraction']['success']) {
            $ext = $response['extraction'];
            $durationSec = $ext['duration_seconds'] ?? 0;
            $this->line("   <fg=green>✓</> <fg=yellow>Extraction:</> {$ext['message']} <fg=gray>({$durationSec}s)</>");
        }

        // Auto-deletion results
        if (isset($response['auto_deletions']) && $response['auto_deletions']) {
            $del = $response['auto_deletions'];
            $count = count($del['deleted'] ?? []);
            if ($count > 0) {
                $this->line("   <fg=yellow>Auto-Cleanup:</> Removed {$count} deleted file(s)");
                foreach ($del['deleted'] as $file) {
                    $this->line("     <fg=red>-</> <fg=gray>{$file}</>");
                }
            }
        }
    }

    /**
     * Extract readable text from HTML error responses
     */
    protected function extractTextFromHtml($html)
    {
        if (strpos($html, 'Service Unavailable') !== false) {
            return 'Laravel 503 - Site in maintenance mode';
        }
        if (strpos($html, 'Server Error') !== false) {
            return 'Laravel 500 - Server Error';
        }
        if (strpos($html, 'Not Found') !== false) {
            return 'Laravel 404 - Route not found';
        }
        
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim(substr($text, 0, 300));
    }

    /**
     * Call the init-deploy.php bootstrap endpoint for first-time deployment
     */
    protected function callInitEndpoint($env, $zipName = 'deploy.zip')
    {
        $remoteUrl = config("deployer.environments.$env.remote_base_url");
        $token = config("deployer.environments.$env.deploy_token");

        if (!$remoteUrl || !$token) {
            return [
                'success' => false,
                'error' => "Missing remote_base_url or deploy_token for environment: {$env}"
            ];
        }

        $url = rtrim($remoteUrl, '/') . '/init-deploy.php?' . http_build_query([
            'token' => $token,
            'zip' => $zipName
        ]);

        $this->line("   Calling: <fg=cyan>" . rtrim($remoteUrl, '/') . "/init-deploy.php</>");

        try {
            $response = Http::timeout(300)->get($url);

            if (!$response->successful()) {
                $body = $response->body();
                
                // Try to parse as JSON first
                $json = json_decode($body, true);
                if ($json && isset($json['error'])) {
                    return [
                        'success' => false,
                        'error' => $json['error']
                    ];
                }

                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}",
                    'raw' => substr($body, 0, 200)
                ];
            }

            return $response->json() ?? [
                'success' => false,
                'error' => 'Invalid JSON response',
                'raw' => substr($response->body(), 0, 200)
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'error' => "Connection Error: {$e->getMessage()}"
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Request Error: {$e->getMessage()}"
            ];
        }
    }
}
