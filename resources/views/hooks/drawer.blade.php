@php
    use Packstub\Agents\AgentsPlugin;
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Filament\Pages\Chat;
    use Packstub\Agents\Support\AgentModels;

    $plugin = AgentsPlugin::current();
    $show = auth()->check() && Agents::inPanel() && AgentModels::enabled() && $plugin && ($plugin->hasSlideOver() || $plugin->getShortcut() !== null) && ! request()->routeIs('*.pages.chat', '*.pages.chat.*');
@endphp
@if ($show)
    {{-- The chat as a slide-over on the right of any page: the chat page in a frame without the panel's chrome, opened
         by the "Ask …" button (with the record being viewed as context) or the keyboard shortcut, closed with Esc. --}}
    <div
        x-data="{
            open: false,
            src: '',
            keys: @js($plugin->getShortcut()),
            show(url) {
                this.src = url + (url.includes('?') ? '&' : '?') + 'embedded=1'
                this.open = true
                document.body.style.overflow = 'hidden'
            },
            hide() {
                this.open = false
                document.body.style.overflow = ''
            },
            matches(event) {
                if (! this.keys) return false
                const parts = this.keys.toLowerCase().split('+')
                const key = parts.pop()
                const mod = parts.includes('mod') ? (event.metaKey || event.ctrlKey) : true
                const shift = parts.includes('shift') ? event.shiftKey : ! event.shiftKey
                const alt = parts.includes('alt') ? event.altKey : ! event.altKey
                return mod && shift && alt && event.key.toLowerCase() === key
            },
        }"
        x-on:open-agent-drawer.window="show($event.detail.url)"
        x-on:keydown.window="if (matches($event)) { $event.preventDefault(); open ? hide() : show(@js(Chat::getUrl())) }"
        x-on:keydown.escape.window="if (open) hide()"
    >
        <template x-teleport="body">
            <div class="fi-agent-drawer" x-show="open" x-cloak x-transition.opacity.duration.150ms role="dialog" aria-modal="true" aria-label="{{ Agents::name() }}">
                <div class="fi-agent-drawer-backdrop" x-on:click="hide()"></div>
                <div class="fi-agent-drawer-panel" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0">
                    <div class="fi-agent-drawer-head">
                        <span><x-filament::icon icon="heroicon-m-sparkles" /> {{ Agents::name() }}@if ($plugin->getShortcutLabel())<kbd class="fi-agent-drawer-kbd">{{ $plugin->getShortcutLabel() }}</kbd>@endif</span>
                        <span class="fi-agent-drawer-actions">
                            <x-filament::icon-button icon="heroicon-m-arrow-top-right-on-square" color="gray" size="sm" :label="__('Open full page')" :tooltip="__('Open full page')" x-on:click="window.location.href = src.replace(/[?&]embedded=1/, '')" />
                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" size="sm" :label="__('Close')" :tooltip="__('Close')" x-on:click="hide()" />
                        </span>
                    </div>
                    <template x-if="open">
                        <iframe class="fi-agent-drawer-frame" x-bind:src="src" title="{{ Agents::name() }}"></iframe>
                    </template>
                </div>
            </div>
        </template>
    </div>
@endif
