<?php
namespace Ram\Deployer\Console;

use Illuminate\Console\Command;
use Ram\Deployer\Console\Concerns\InteractsWithDeployerApi;

class LaraRemoteCmdCommand extends Command
{
    use InteractsWithDeployerApi;

    protected $signature = 'ftp:cmd 
        {--env= : Target environment (default, production, staging)}
        {--cmd=* : Artisan command(s) to run on remote server}';
    
    protected $description = 'Execute Artisan commands on remote server without deployment';

    protected $startTime;

    public function handle()
    {
        $this->startTime = microtime(true);
        
        $env = $this->option('env') ?: 'default';
        $commands = $this->option('cmd');

        if (empty($commands)) {
            $this->error('❌ No commands specified. Use --cmd="your:command"');
            return 1;
        }

        // Validate environment using trait
        $settings = $this->validateEnvironment($env);
        if (!$settings) {
            return 1;
        }

        $this->newLine();
        $this->printHeader("⚡ REMOTE COMMAND EXECUTION [{$env}]");

        $this->printStep('Remote Commands');
        $this->line("   Endpoint: <fg=cyan>{$this->getDeployerEndpoint($env)}</>");
        $this->line("   Commands: <fg=cyan>" . count($commands) . "</>");
        $this->newLine();

        // Run each command individually using trait method
        foreach ($commands as $cmd) {
            $this->line("   <fg=gray>⏳ Running:</> <fg=white>{$cmd}</>");
            
            $cmdResponse = $this->callDeployerEndpoint(
                $env, 
                [$cmd],
                ['zipname' => 'skip-deployment.zip']
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
                    foreach ($lines as $line) {
                        if (trim($line)) {
                            $this->line("     <fg=gray>{$line}</>");
                        }
                    }
                }
            } else {
                $this->line("\033[1A\033[2K   <fg=red>✗</> <fg=white>{$cmd}</> <fg=gray>(failed)</>");
                if (isset($cmdResponse['error'])) {
                    $this->line("     <fg=red>{$cmdResponse['error']}</>");
                }
            }
            
            $this->newLine();
        }

        // Final summary
        $this->printFooter();
        
        return 0;
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
        $this->line("<fg=white;bg=blue>  {$title}                    </>");
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
        $this->line('<fg=green>✅ COMMANDS EXECUTED</>');
        $this->line("   Total time: <fg=cyan>{$this->getElapsedTime()}</>");
        $this->line('<fg=green>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();
    }
}
