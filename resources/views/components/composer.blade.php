@props([
    'method' => 'send',
    'targets' => null,
    'placeholder' => null,
    'rows' => 1,
    'label' => null,
    'autofocus' => false,
])
@php
    $targets ??= $method;
    $placeholder ??= __('Ask a follow-up…');
    $label ??= __('Send');
@endphp
{{-- Driven by the page's agentChat Alpine component (resources/js/agent-chat.js): the field never locks — a question
     typed while an answer streams is queued and sent next. Enter sends, Shift+Enter breaks the line, ↑ edits the last queued question. --}}
<form
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer rounded-2xl border-2 border-primary-300 bg-white shadow-sm focus-within:border-primary-500 dark:border-primary-500/40 dark:bg-gray-900 dark:focus-within:border-primary-400']) }}
>
    <textarea
        x-ref="input"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        class="block w-full resize-none border-0 bg-transparent px-5 pt-4 text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
        x-on:input="autosize()"
        x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit() }"
        x-on:keydown.up="recall($event)"
        x-on:keydown.escape="$refs.input.value = ''; autosize()"
        aria-label="{{ $placeholder }}"
        @if ($autofocus) autofocus @endif
    ></textarea>
    <div class="flex items-center justify-between gap-3 px-3 pb-3">
        <x-filament::input.wrapper class="w-32">
            <x-filament::input.select wire:model="model">
                @foreach (\Packstub\Agents\Support\AgentModels::options() as $key => $modelLabel)
                    <option value="{{ $key }}">{{ $modelLabel }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <div class="flex items-center gap-3">
            <span class="hidden text-xs text-gray-400 sm:inline" x-show="busy" x-cloak>{{ __('Keep typing — the next question is sent when this answer is done.') }}</span>
            <x-filament::button type="submit" icon="heroicon-m-arrow-up" size="sm">{{ $label }}</x-filament::button>
        </div>
    </div>
</form>
