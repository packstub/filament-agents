<?php

namespace Packstub\Agents\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Scaffold the app's agent class: the persona and domain slots to fill, on top of the package's base agent. */
class MakeAgentCommand extends Command
{
    protected $signature = 'packstub-agents:agent {name=Assistant : The class name} {--force}';

    protected $description = 'Create the app\'s in-panel agent class (extends Packstub\Agents\Ai\Agent)';

    public function handle(Filesystem $files): int
    {
        $class = Str::studly($this->argument('name'));
        $path = app_path('Ai/Agents/'.$class.'.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->warn("{$path} already exists (use --force to overwrite).");

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, str_replace(['{{ class }}', '{{ name }}'], [$class, config('packstub-agents.name', 'Assistant')], $files->get(__DIR__.'/../../stubs/agent.stub')));

        $this->components->info("Agent created: {$path}");
        $this->line('Register it: AgentsPlugin::make()->agent(\App\Ai\Agents\\'.$class.'::class)');

        return self::SUCCESS;
    }
}
