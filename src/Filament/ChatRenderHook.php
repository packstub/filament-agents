<?php

namespace Packstub\Agents\Filament;

/**
 * Where an app adds its own controls to the chat page, without publishing the view: register them with
 * FilamentView::registerRenderHook(ChatRenderHook::…, fn () => …, scopes: Chat::class). Rendered inside the page's
 * Livewire component, so the markup may call the page's actions (wire:click) and read its Alpine state.
 */
final class ChatRenderHook
{
    /** In the composer's row, before the page's own tools (the context ring): a toggle, a mode, a shortcut. */
    public const string COMPOSER_TOOLS_BEFORE = 'packstub-agents::chat.composer.tools.before';

    /** Under the composer, sticking to the bottom of the page with it: a row of the app's own chat actions. */
    public const string COMPOSER_AFTER = 'packstub-agents::chat.composer.after';
}
