<?php

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Packstub\Agents\Filament\Pages\TurnLog;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;
use Packstub\Agents\Filament\Resources\AgentLimits\Pages\ManageAgentLimits;
use Packstub\Agents\Filament\Widgets\TurnStats;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Tests\Fixtures\Models\Team;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The operator pages registered on a panel with tenancy (#75): a member sees and edits the current
 * workspace's rows alone; the operator console, without tenancy, keeps every row.
 */
beforeEach(function () {
    $this->admin = $this->user(['is_admin' => true]);
    $this->acme = Team::query()->create(['owner_id' => $this->admin->id, 'name' => 'Acme', 'slug' => 'acme']);
    $this->globex = Team::query()->create(['owner_id' => $this->user()->id, 'name' => 'Globex', 'slug' => 'globex']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    actingAs($this->admin);
});

it('shows a workspace its own turns alone on a panel with tenancy', function () {
    $turn = fn (?Team $team, string $provider) => AgentTurn::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => (string) Str::uuid(), 'participant_type' => $this->admin->getMorphClass(), 'participant_id' => $this->admin->id,
        'status' => AgentTurn::DONE, 'input' => ['prompt' => 'Hi'], 'model' => 'auto', 'panel' => 'admin', 'provider' => $provider, 'model_name' => 'test-'.$provider,
        'tenant' => $team?->getKey(), 'started_at' => now(), 'finished_at' => now(),
    ]);
    $mine = $turn($this->acme, 'anthropic');
    $theirs = $turn($this->globex, 'openai');
    $nowhere = $turn(null, 'gemini');

    Filament::setTenant($this->acme);

    expect(TurnLog::turns()->pluck('id')->all())->toBe([$mine->id]);
    livewire(TurnLog::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $nowhere])
        ->assertSee('test-anthropic')
        ->assertDontSee('test-openai');
    livewire(TurnStats::class)->assertSee(__(':count this week', ['count' => 1]));

    // The operator console, without a workspace, lists them all as before.
    Filament::setTenant(null);
    expect(TurnLog::turns()->count())->toBe(3);
    livewire(TurnLog::class)->assertCanSeeTableRecords([$mine, $theirs, $nowhere]);
    livewire(TurnStats::class)->assertSee(__(':count this week', ['count' => 3]));
});

it('lets a workspace see and edit its own limits row alone on a panel with tenancy', function () {
    $global = AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 50]);
    $theirs = AgentLimit::query()->create(['scope' => 'tenant', 'scope_id' => (string) $this->globex->id, 'turns_per_day' => 10]);
    $user = AgentLimit::query()->create(['scope' => 'user', 'scope_id' => (string) $this->admin->id, 'turns_per_minute' => 2]);

    Filament::setTenant($this->acme);

    expect(AgentLimitResource::canViewAny())->toBeTrue()
        ->and(AgentLimitResource::getEloquentQuery()->count())->toBe(0)
        ->and(AgentLimitResource::canEdit($theirs))->toBeFalse()
        ->and(AgentLimitResource::canDelete($theirs))->toBeFalse()
        ->and(AgentLimitResource::canEdit($global))->toBeFalse()
        // What the page saves carries the workspace's scope whatever the form sent.
        ->and(AgentLimitResource::forceScope(['scope' => 'user', 'scope_id' => '9', 'turns_per_day' => 5]))->toBe(['scope' => 'tenant', 'scope_id' => (string) $this->acme->id, 'turns_per_day' => 5]);

    // The pickers offer the current workspace alone: another one fails validation, the right one creates its row.
    livewire(ManageAgentLimits::class)
        ->assertCanNotSeeTableRecords([$global, $theirs, $user])
        ->callAction(CreateAction::class, ['scope' => 'tenant', 'scope_id' => (string) $this->globex->id, 'turns_per_day' => 5])
        ->assertHasActionErrors(['scope_id']);
    livewire(ManageAgentLimits::class)
        ->callAction(CreateAction::class, ['scope' => 'tenant', 'scope_id' => (string) $this->acme->id, 'turns_per_day' => 5, 'enabled' => '0'])
        ->assertHasNoActionErrors();
    $mine = AgentLimit::query()->where('scope', 'tenant')->where('scope_id', (string) $this->acme->id)->firstOrFail();
    expect($mine)->toMatchArray(['turns_per_day' => 5, 'enabled' => false])
        ->and($theirs->fresh()->turns_per_day)->toBe(10)
        ->and(AgentLimitResource::canEdit($mine))->toBeTrue()
        ->and(AgentLimitResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine->id]);

    // An edit keeps the row on the workspace, and another workspace's row cannot be opened.
    livewire(ManageAgentLimits::class)
        ->assertCanSeeTableRecords([$mine])
        ->callAction(TestAction::make(EditAction::class)->table($mine), ['scope' => 'tenant', 'scope_id' => (string) $this->acme->id, 'turns_per_day' => 7])
        ->assertHasNoActionErrors();
    expect($mine->fresh())->toMatchArray(['scope' => 'tenant', 'scope_id' => (string) $this->acme->id, 'turns_per_day' => 7])
        ->and(fn () => livewire(ManageAgentLimits::class)->callAction(TestAction::make(EditAction::class)->table($theirs), ['turns_per_day' => 1]))
        ->toThrow(ActionNotResolvableException::class)
        ->and($theirs->fresh()->turns_per_day)->toBe(10);

    // The operator console, without a workspace, keeps every row and every scope.
    Filament::setTenant(null);
    expect(AgentLimitResource::getEloquentQuery()->count())->toBe(4)
        ->and(AgentLimitResource::canEdit($theirs))->toBeTrue()
        ->and(AgentLimitResource::forceScope(['scope' => 'user']))->toBe(['scope' => 'user']);
    livewire(ManageAgentLimits::class)->assertCanSeeTableRecords([$global, $theirs, $user, $mine]);
});
