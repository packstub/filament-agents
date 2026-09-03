<?php

use Packstub\Agents\Filament\Pages\AgentAccess;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('mints agent access tokens from the panel and lists them', function () {
    $user = $this->user();
    actingAs($user);

    expect(AgentAccess::canAccess())->toBeTrue()->and(AgentAccess::getNavigationGroup())->toBe('Setup');

    $page = livewire(AgentAccess::class)
        ->assertSee('Agent access')
        ->callAction('create', ['label' => 'Claude Code', 'abilities' => ['read', 'write']])
        ->assertHasNoActionErrors()
        ->assertSee('claude mcp add');

    $token = $user->tokens()->first();
    expect($token->name)->toBe('Claude Code')->and($token->abilities)->toBe(['read', 'write']);
    expect($page->get('plainTextToken'))->toStartWith($token->id.'|');

    $page->assertCanSeeTableRecords([$token]);

    Abilities::$allowed = ['widgets.view'];
    expect(AgentAccess::canAccess())->toBeFalse();
});

it('resolves operator limits from config, global, workspace and user rows and guards the resource', function () {
    $owner = $this->user();
    actingAs($owner);
    config(['packstub-agents.limits' => ['turns_per_minute' => 6, 'turns_per_day' => 150, 'tokens_per_month' => 3000000, 'user_tokens_per_day' => 300000, 'user_tokens_per_month' => 1500000, 'prompt_max_chars' => 2000]]);
    AgentLimits::flush();

    expect(AgentLimits::effective())->toMatchArray(['enabled' => true, 'turns_per_day' => 150, 'user_tokens_per_day' => 300000, 'user_tokens_per_month' => 1500000]);

    AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 50, 'tokens_per_month' => 100000, 'user_tokens_per_day' => 1000]);
    AgentLimit::query()->create(['scope' => 'user', 'scope_id' => (string) $owner->id, 'user_tokens_per_month' => 500, 'turns_per_day' => 999]);
    AgentLimits::flush();

    $limits = AgentLimits::effective();
    expect($limits['turns_per_day'])->toBe(50)          // the user row may not set it
        ->and($limits['tokens_per_month'])->toBe(100000) // global row beats config
        ->and($limits['user_tokens_per_day'])->toBe(1000)
        ->and($limits['user_tokens_per_month'])->toBe(500)
        ->and($limits['turns_per_minute'])->toBe(6);     // config default

    expect(AgentLimit::query()->where('scope', 'user')->first()->targetLabel())->toBe($owner->email)
        ->and(AgentLimit::query()->where('scope', 'global')->first()->targetLabel())->toBe('Everyone');

    // The resource is for whoever the plugin's authorize callback allows — here, admins.
    expect(AgentLimitResource::canViewAny())->toBeFalse();
    actingAs($this->user(['is_admin' => true]));
    expect(AgentLimitResource::canViewAny())->toBeTrue();
    expect(WidgetResource::agentKey())->toBe('widgets');
});
