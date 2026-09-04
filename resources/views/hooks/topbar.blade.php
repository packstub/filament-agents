@php
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Filament\Pages\Chat;
    use Packstub\Agents\Support\AgentModels;
    use Packstub\Agents\Support\PageContext;

    $show = auth()->check() && Agents::inPanel() && AgentModels::enabled() && ! request()->routeIs(...Agents::askButtonHiddenOn());
@endphp
@if ($show)
    <x-filament::button
        tag="a"
        :href="Chat::getUrl(array_filter(['context' => PageContext::fromRequest()]))"
        color="primary"
        outlined
        size="sm"
        icon="heroicon-m-sparkles"
        class="fi-ask-agent me-2"
    >
        {{ Agents::name() }}
    </x-filament::button>
@endif
