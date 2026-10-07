<?php

use Filament\Facades\Filament;
use Packstub\Agents\Tests\Fixtures\Models\Team;

use function Pest\Laravel\postJson;

// The default path, "mcp", with no {tenant}: fine for a panel without tenancy, refused once the panel has it.
it('refuses the MCP path without {tenant} once the panel has tenancy', function () {
    $owner = $this->user();
    Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $call = fn (string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list-widgets', 'arguments' => ['limit' => 1]]], ['Authorization' => 'Bearer '.$token] + $mcp);

    $token = $owner->createToken('laptop', ['read'])->plainTextToken;
    $call($token)->assertOk()->assertJsonPath('result.isError', false);

    // The panel's resources are scoped by Filament's tenant only when one is set: with none, refuse rather than list every workspace.
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    auth()->forgetGuards();
    $bound = $owner->createToken('desk', ['read', 'tenant:acme'])->plainTextToken;
    $call($bound)->assertNotFound()->assertJsonPath('error', fn (string $e) => str_contains($e, 'mcp/{tenant}'));
    expect(Filament::getTenant())->toBeNull();
});

it('refuses an MCP request from a person the panel no longer admits, token or not', function () {
    $owner = $this->user();
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $token = $owner->createToken('laptop', ['read'])->plainTextToken;
    $call = fn () => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list-widgets', 'arguments' => ['limit' => 1]]], ['Authorization' => 'Bearer '.$token] + $mcp);

    $call()->assertOk()->assertJsonPath('result.isError', false);

    // Suspended after minting the token: canAccessPanel() says no, as it does on a page, so the token stops working.
    $owner->forceFill(['suspended_at' => now()])->save();
    auth()->forgetGuards();
    $call()->assertForbidden()->assertJsonPath('error', 'You do not have access to this panel.');
    expect(Filament::auth()->user())->toBeNull();
});
