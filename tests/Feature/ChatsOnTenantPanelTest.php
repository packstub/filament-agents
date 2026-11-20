<?php

use Filament\Facades\Filament;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\FilamentAgents\Pages\Chats;
use Packstub\FilamentAgents\Tests\Fixtures\Models\Team;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/** One person in two workspaces: the chats list and the sidebar show the chats started in the current one alone. */
it('lists only the chats started in the current workspace, in the list and in the sidebar', function () {
    $user = $this->user();
    $acme = Team::query()->create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['owner_id' => $user->id, 'name' => 'Globex', 'slug' => 'globex']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    actingAs($user);
    $store = app(AgentConversationStore::class);

    Filament::setTenant($acme);
    $inAcme = Conversation::query()->find($store->startConversation($user, 'Acme payroll'));
    Filament::setTenant($globex);
    $inGlobex = Conversation::query()->find($store->startConversation($user, 'Globex payroll'));

    livewire(Chats::class)
        ->assertCanSeeTableRecords([$inGlobex])
        ->assertCanNotSeeTableRecords([$inAcme]);

    $sidebar = view('packstub-agents::hooks.sidebar')->render();
    expect($sidebar)->toContain('Globex payroll')->not->toContain('Acme payroll');
});
