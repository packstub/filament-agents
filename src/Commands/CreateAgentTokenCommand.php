<?php

namespace Packstub\Agents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packstub\Agents\Actions\CreateAgentToken;

class CreateAgentTokenCommand extends Command
{
    protected $signature = 'packstub:agents:create-token
        {name=Agent : The label for the agent token}
        {--grant=* : Grants in tool:mode form, e.g. list-packages:read}
        {--expires= : Optional expiration datetime}';

    protected $description = 'Create an agent token for MCP access, with per-tool capability grants';

    public function handle(CreateAgentToken $action): int
    {
        $grants = [];

        foreach ($this->option('grant') as $grant) {
            if (! str_contains((string) $grant, ':')) {
                $this->components->error("Invalid grant [{$grant}]. Use tool:mode, e.g. list-packages:read.");

                return self::FAILURE;
            }

            $grants[Str::before($grant, ':')] = Str::after($grant, ':');
        }

        $expiresAt = $this->option('expires') !== null
            ? Carbon::parse($this->option('expires'))
            : null;

        try {
            $result = $action->handle((string) $this->argument('name'), $grants, $expiresAt);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Agent token created.');
        $this->line('  Name:      '.$result->agentToken->name);
        $this->line('  Public ID: '.$result->agentToken->public_id);
        $this->line('  Grants:    '.($grants === [] ? '(none)' : json_encode($grants)));
        $this->newLine();
        $this->components->warn('Store this bearer token now — it will not be shown again:');
        $this->line('  '.$result->plainTextToken);

        return self::SUCCESS;
    }
}
