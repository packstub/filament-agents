@props([
    'method' => 'send',
    'targets' => null,
    'placeholder' => null,
    'rows' => 1,
    'label' => null,
    'autofocus' => false,
    'stoppable' => true,
    'model' => null,
])
{{-- Slot `tools`: extra controls on the row, between the field and Stop (the chat page's context ring). --}}
@php
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Filament\Pages\Chat;
    use Packstub\Agents\Support\AgentModels;

    $targets ??= $method;
    $placeholder ??= Agents::name().'…';
    $label ??= __('Send');
    $hint = __('Keep typing — the next question is sent when this answer is done.');
    $menu = Chat::modelMenu();
    $picked = $model ?? AgentModels::current();
    $pickedLabel = collect($menu)->collapse()->get($picked)['label'] ?? \Illuminate\Support\Str::headline($picked);
@endphp
{{-- One row: the question, then the tools, Stop while an answer runs, the model and a square Send. Driven by the page's
     agentChat Alpine component (resources/js/agent-chat.js): the field never locks — a question typed while an answer
     runs waits its turn on the server, and the placeholder says so meanwhile. Enter sends, Shift+Enter breaks the line,
     ↑ edits the last waiting question, Stop cuts the running answer short. --}}
<form
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer rounded-2xl border border-gray-950/10 bg-white shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:focus-within:border-primary-400 dark:focus-within:ring-primary-400']) }}
>
    <div class="flex items-center justify-between gap-3 px-4 pt-2.5 text-xs text-gray-500" x-show="editing" x-cloak>
        <span class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
            {{ __('Editing your last question — its answer will be replaced.') }}
        </span>
        <button type="button" class="hover:text-gray-700 hover:underline dark:hover:text-gray-200" x-on:click="cancelEdit()">{{ __('Cancel') }}</button>
    </div>
    <div class="fi-agent-composer-row">
        <textarea
            x-ref="input"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            x-bind:placeholder="busy ? @js($hint) : @js($placeholder)"
            class="fi-agent-composer-input block w-full resize-none border-0 bg-transparent text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
            x-on:input="autosize()"
            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit() }"
            x-on:keydown.up="recall($event)"
            x-on:keydown.escape="editing ? cancelEdit() : ($refs.input.value = '', autosize())"
            aria-label="{{ $placeholder }}"
            @if ($autofocus) autofocus @endif
        ></textarea>
        <div class="fi-agent-composer-actions">
            {{ $tools ?? '' }}
            @if ($stoppable)
                <x-filament::icon-button icon="heroicon-m-stop" color="gray" size="sm" :label="__('Stop')" :tooltip="__('Stop')" class="fi-agent-composer-stop" x-show="turn !== null" x-cloak x-on:click="stop()" x-bind:disabled="stopping" />
            @endif
            {{-- The model: a small text button that opens the list, the picked one ticked; entries of more than one
                 provider (a Gemini entry on an Anthropic picker) sit under provider headings. --}}
            <x-filament::dropdown placement="top-end" width="xs">
                <x-slot name="trigger">
                    <button type="button" class="fi-agent-composer-model" aria-label="{{ __('Model: :model', ['model' => $pickedLabel]) }}">
                        <span class="truncate">{{ $pickedLabel }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-up-down" />
                    </button>
                </x-slot>
                <x-filament::dropdown.list class="fi-agent-composer-models">
                    @foreach ($menu as $modelProvider => $entries)
                        @if (count($menu) > 1)
                            <x-filament::dropdown.header>{{ AgentModels::providerLabel($modelProvider) }}</x-filament::dropdown.header>
                        @endif
                        @foreach ($entries as $key => $entry)
                            <x-filament::dropdown.list.item :class="$key === $picked ? 'fi-agent-composer-model-picked' : ''" wire:click="$set('model', '{{ $key }}')" x-on:click="close()">
                                <span class="fi-agent-composer-model-option">
                                    <span class="fi-agent-composer-model-label">{{ $entry['label'] }}</span>
                                    @if ($entry['detail'])
                                        <span class="fi-agent-composer-model-detail">{{ $entry['detail'] }}</span>
                                    @endif
                                </span>
                                @if ($key === $picked)
                                    <x-filament::icon icon="heroicon-m-check" class="fi-agent-composer-model-check" />
                                @endif
                            </x-filament::dropdown.list.item>
                        @endforeach
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
            <x-filament::button type="submit" icon="heroicon-m-arrow-up" size="sm" label-sr-only class="fi-agent-composer-send">{{ $label }}</x-filament::button>
        </div>
    </div>
</form>
