# Upgrading

## From 1.19 to 1.20

1.20 renders the chat that ships with [Agents for Laravel](https://packstub.dev/docs/agents/chat) and gives the plugin a namespace of its own. Most apps change one `use` line:

```bash
composer update packstub/filament-agents packstub/agents
php artisan filament:assets
```

It requires `packstub/agents` 1.14 or newer and has no migrations. An app that caches Filament's components or its views (`filament:cache-components`, `view:cache`) builds those caches again on deploy, as after any update.

### The plugin's namespace

The plugin's classes move from `Packstub\Agents` to `Packstub\FilamentAgents`. The engine's classes keep their names, so tools, agents, resources and the facade need no change.

| Before | After |
| --- | --- |
| `Packstub\Agents\AgentsPlugin` | `Packstub\FilamentAgents\AgentsPlugin` |
| `Packstub\Agents\Filament\Pages\Chat` (also `Chats`, `AgentAccess`, `TurnLog`, `ResourceTable`) | `Packstub\FilamentAgents\Pages\Chat` … |
| `Packstub\Agents\Filament\ChatRenderHook` | `Packstub\FilamentAgents\ChatRenderHook` |
| `Packstub\Agents\Filament\FilamentContext` | `Packstub\FilamentAgents\FilamentContext` |
| `Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource` | `Packstub\FilamentAgents\Resources\AgentLimits\AgentLimitResource` |
| `Packstub\Agents\Filament\Widgets\TurnStats`, `TurnsChart` | `Packstub\FilamentAgents\Widgets\TurnStats`, `TurnsChart` |
| `Packstub\Agents\Filament\Forms\ToolPicker` | `Packstub\FilamentAgents\Forms\ToolPicker` |
| `Packstub\Agents\Livewire\AgentTable` | `Packstub\FilamentAgents\Livewire\AgentTable` |
| `Packstub\Agents\Mcp\Tools\ShowTable` | `Packstub\FilamentAgents\Mcp\Tools\ShowTable` |

In short: `Packstub\Agents\Filament\…` becomes `Packstub\FilamentAgents\…` without the `Filament` segment, and the three classes that sat outside it (`AgentsPlugin`, `Livewire\AgentTable`, `Mcp\Tools\ShowTable`) change only their root. A search for `Packstub\Agents\AgentsPlugin` and `Packstub\Agents\Filament\` in your app finds every line. These stay as they are: `Packstub\Agents\Facades\Agents`, `Packstub\Agents\Mcp\AgentTool`, `Packstub\Agents\Ai\Agent`, `Packstub\Agents\Mcp\Tools\DrawChart`, the models, the config file and its keys, the env vars, the routes and the view namespace `packstub-agents::`.

### Published chat views

Only if you published a part of the chat page. The parts now belong to the engine: a file under `resources/views/vendor/packstub-agents/pages/chat/` goes under `resources/views/vendor/packstub-agents-chat/` with the same name (`header`, `question`, `answer/footer` …). A part reads the chat's variables instead of `$this`, and is styled with the classes of the chat's stylesheet instead of Tailwind utilities, so start from the new file (`php artisan vendor:publish --tag=packstub-agents-chat-views`, keep only the one you change) and move your change over. See [Changing one part of the chat page](assistant.md#changing-one-part-of-the-chat-page).

### Tests and render hooks

Only if your tests drive the chat page, or a render hook of yours calls it. The Chat page has no Livewire actions any more: the chat is an Alpine component that calls the panel's routes.

- A test that called `livewire(Chat::class)->call('send')` posts to the panel's `packstub-agents.act` route and then reads the page, as in [Testing](testing.md#faking-the-model).
- Markup in `ChatRenderHook::COMPOSER_TOOLS_BEFORE` or `COMPOSER_AFTER` that used `wire:click` or `$wire` calls the chat's Alpine methods instead (`x-on:click="enqueue('…')"`), see [The slide-over and the shortcut](assistant.md#the-slide-over-and-the-shortcut).

### A link that asks a question

A `?prompt=` link to the chat page now fills the composer and waits for the person to send it. A question your app wants sent on its own goes through the session, as before: `session(['packstub-agents.prompt' => $text])`, then redirect to the page.
