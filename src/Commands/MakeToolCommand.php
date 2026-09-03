<?php

namespace Packstub\Agents\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Scaffold one tool: a laravel/mcp tool with an ability, listed once and served to the chat and to MCP clients. */
class MakeToolCommand extends Command
{
    protected $signature = 'packstub-agents:tool {name : The class name, e.g. SearchOrders} {--write : A tool that changes data (approval-gated in chat)} {--ability= : The ability required to see and run it} {--force}';

    protected $description = 'Create an agent tool class (extends Packstub\Agents\Mcp\AgentTool)';

    public function handle(Filesystem $files): int
    {
        $class = Str::studly($this->argument('name'));
        $path = app_path('Mcp/Tools/'.$class.'.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->warn("{$path} already exists (use --force to overwrite).");

            return self::SUCCESS;
        }

        $ability = $this->option('ability');
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, str_replace(
            ['{{ class }}', '{{ readOnly }}', '{{ ability }}'],
            [$class, $this->option('write') ? '' : "#[IsReadOnly]\n", $ability ? "'{$ability}'" : 'null'],
            $files->get(__DIR__.'/../../stubs/tool.stub'),
        ));

        $this->components->info("Tool created: {$path}");
        $this->line('Add it to your server\'s $tools (or AgentsPlugin::make()->tools([...])) and it is served to the chat and to MCP clients.');

        return self::SUCCESS;
    }
}
