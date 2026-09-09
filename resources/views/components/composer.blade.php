@props([
    'method' => 'send',
    'targets' => null,
    'placeholder' => null,
    'rows' => 1,
    'label' => null,
    'autofocus' => false,
    'stoppable' => true,
])
{{-- Slot `tools`: extra controls in the footer, between the "keep typing" hint and Send (the chat page's context ring). --}}
@php
    $targets ??= $method;
    $placeholder ??= __('Ask a follow-up…');
    $label ??= __('Send');
@endphp
{{-- Driven by the page's agentChat Alpine component (resources/js/agent-chat.js): the field never locks — a question
     typed while an answer runs waits its turn on the server. Enter sends, Shift+Enter breaks the line, ↑ edits the last
     waiting question, Stop cuts the running answer short. --}}
<form
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer rounded-2xl border-2 border-primary-300 bg-white shadow-sm focus-within:border-primary-500 dark:border-primary-500/40 dark:bg-gray-900 dark:focus-within:border-primary-400']) }}
>
    <div class="flex items-center justify-between gap-3 px-5 pt-3 text-xs text-gray-500" x-show="editing" x-cloak>
        <span class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
            {{ __('Editing your last question — its answer will be replaced.') }}
        </span>
        <button type="button" class="hover:text-gray-700 hover:underline dark:hover:text-gray-200" x-on:click="cancelEdit()">{{ __('Cancel') }}</button>
    </div>
    <textarea
        x-ref="input"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        class="block w-full resize-none border-0 bg-transparent px-5 pt-4 text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
        x-on:input="autosize()"
        x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit() }"
        x-on:keydown.up="recall($event)"
        x-on:keydown.escape="editing ? cancelEdit() : ($refs.input.value = '', autosize())"
        aria-label="{{ $placeholder }}"
        @if ($autofocus) autofocus @endif
    ></textarea>
    <div class="flex items-center justify-between gap-3 px-3 pb-3">
        <x-filament::input.wrapper class="w-32">
            {{-- Entries of more than one provider (a Gemini entry on an Anthropic picker) sit under provider headings. --}}
            <x-filament::input.select wire:model="model">
                @php($modelGroups = \Packstub\Agents\Support\AgentModels::groups())
                @foreach ($modelGroups as $modelProvider => $modelOptions)
                    @if (count($modelGroups) > 1)
                        <optgroup label="{{ \Packstub\Agents\Support\AgentModels::providerLabel($modelProvider) }}">
                    @endif
                    @foreach ($modelOptions as $key => $modelLabel)
                        <option value="{{ $key }}">{{ $modelLabel }}</option>
                    @endforeach
                    @if (count($modelGroups) > 1)
                        </optgroup>
                    @endif
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <div class="flex items-center gap-3">
            {{ $tools ?? '' }}
            <span class="hidden text-xs text-gray-400 sm:inline" x-show="busy" x-cloak>{{ __('Keep typing — the next question is sent when this answer is done.') }}</span>
            @if ($stoppable)
                <x-filament::button type="button" color="gray" outlined size="sm" icon="heroicon-m-stop" x-show="turn !== null" x-cloak x-on:click="stop()" x-bind:disabled="stopping">
                    <span x-show="! stopping">{{ __('Stop') }}</span>
                    <span x-show="stopping" x-cloak>{{ __('Stopping…') }}</span>
                </x-filament::button>
            @endif
            <x-filament::button type="submit" icon="heroicon-m-arrow-up" size="sm">{{ $label }}</x-filament::button>
        </div>
    </div>
</form>
