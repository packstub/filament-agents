@php
    $tools = $getTools();
    $writeEnabled = $isWriteEnabled();
    $statePath = $getStatePath();
    $writeNames = collect($tools)->reject(fn ($t) => $t['readOnly'])->keys()->values()->all();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle('{{ $statePath }}'),
            names: @js(array_keys($tools)),
            get all() { return this.names.length > 0 && this.names.every((n) => this.state.includes(n)) },
            toggleAll() { this.state = this.all ? [] : [...this.names] },
        }"
        class="fi-agent-tools"
    >
        <table>
            <thead>
                <tr>
                    <th class="fi-agent-tools-check">
                        <x-filament::input.checkbox x-bind:checked="all" x-on:change="toggleAll()" :aria-label="__('Select all')" />
                    </th>
                    <th>{{ __('Tool') }}</th>
                    <th>{{ __('Access') }}</th>
                    <th>{{ __('What it does') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tools as $name => $tool)
                    @php $off = ! $tool['readOnly'] && ! $writeEnabled; @endphp
                    <tr @class(['fi-agent-tools-off' => $off]) @if ($off) title="{{ __('Tick Write above to scope write tools.') }}" @endif>
                        <td class="fi-agent-tools-check">
                            <x-filament::input.checkbox :id="$getId().'-'.$name" :value="$name" x-model="state" :disabled="$off" />
                        </td>
                        <td class="fi-agent-tools-name"><label for="{{ $getId().'-'.$name }}">{{ $tool['title'] }}</label></td>
                        <td>
                            <x-filament::badge :color="$tool['readOnly'] ? 'gray' : 'warning'" size="sm">
                                {{ $tool['readOnly'] ? __('Read') : __('Write') }}
                            </x-filament::badge>
                        </td>
                        <td class="fi-agent-tools-desc" title="{{ $tool['description'] }}">{{ $tool['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-dynamic-component>
