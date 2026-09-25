@php
    use Packstub\Agents\AgentsPlugin;
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Filament\Pages\Chat;
    use Packstub\Agents\Support\AgentModels;
    use Packstub\Agents\Support\PageContext;

    $show = auth()->check() && Agents::inPanel() && AgentModels::enabled() && ! request()->routeIs(...Agents::askButtonHiddenOn());
    $plugin = AgentsPlugin::current();
    $url = Chat::getUrl(array_filter(['context' => PageContext::fromRequest()]));
    $slideOver = $plugin?->hasSlideOver() ?? false;
    $shortcut = $plugin?->getShortcutLabel();
@endphp
@if ($show)
    {{-- Opens the chat in a slide-over over the page (the record being viewed as context), or the chat page itself. --}}
    <x-filament::button
        tag="a"
        :href="$url"
        color="gray"
        outlined
        size="sm"
        icon="heroicon-m-sparkles"
        class="fi-ask-agent me-2"
        :x-on:click.prevent="$slideOver ? '$dispatch(\'open-agent-drawer\', { url: '.\Illuminate\Support\Js::from($url).' })' : null"
        :x-tooltip="$shortcut ? '{ content: '.\Illuminate\Support\Js::from(__('Shortcut: :keys', ['keys' => $shortcut])).', theme: $store.theme }' : null"
    >
        {{ Agents::name() }}
    </x-filament::button>
@endif
