<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section icon="heroicon-o-key" icon-color="success">
            <x-slot name="heading">{{ __('Your new token') }}</x-slot>
            <x-slot name="description">{{ __('Copy it now — it is shown only once.') }}</x-slot>
            <pre class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-white">{{ $plainTextToken }}</pre>
            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Claude Code') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-white">claude mcp add --transport http {{ $this->serverSlug() }} {{ $this->mcpUrl() }} --header "Authorization: Bearer {{ $plainTextToken }}"</pre>
            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Claude Desktop / any MCP client') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-white">{ "mcpServers": { "{{ $this->serverSlug() }}": { "type": "http", "url": "{{ $this->mcpUrl() }}", "headers": { "Authorization": "Bearer {{ $plainTextToken }}" } } } }</pre>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-cpu-chip" collapsible collapsed>
        <x-slot name="heading">{{ __('How it works') }}</x-slot>
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __(':name is also an MCP server. An agent that connects with your token sees the same tools the in-panel chat has and acts with your role in this workspace only.', ['name' => \Packstub\Agents\Facades\Agents::name()]) }}</p>
            <p>{{ __('Server URL:') }} <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">{{ $this->mcpUrl() }}</code></p>
            <p>{{ __('A read-only token can look but never change anything. A write token can change data through the tools — but only what your role could do by hand.') }}</p>
            <p>{{ __('Narrow a token to the tools an agent needs: it then sees only those, and nothing else, even if your role allows more. Give it an expiry when it lives on a machine you do not control. Revoke a token here at any time.') }}</p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
