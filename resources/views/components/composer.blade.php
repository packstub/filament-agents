@props([
    'method' => 'send',
    'targets' => null,
    'placeholder' => null,
    'rows' => 2,
    'label' => null,
    'autofocus' => false,
])
@php
    $targets ??= $method;
    $placeholder ??= __('Ask a follow-up…');
    $label ??= __('Send');
@endphp
{{-- The field is cleared here, client-side: the server does the same, but Livewire only syncs it once the streamed response ends. --}}
<form
    x-data="{ submit() { $wire.{{ $method }}(); $nextTick(() => { $refs.input.value = '' }) } }"
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer rounded-2xl border-2 border-primary-300 bg-white shadow-sm focus-within:border-primary-500 dark:border-primary-500/40 dark:bg-gray-900 dark:focus-within:border-primary-400']) }}
>
    <textarea
        x-ref="input"
        wire:model="prompt"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        class="block w-full resize-none border-0 bg-transparent px-5 pt-4 text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
        x-on:keydown.enter.prevent="if (!$event.shiftKey) submit()"
        wire:loading.attr="readonly"
        wire:target="{{ $targets }}"
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
        <x-filament::button type="submit" icon="heroicon-m-arrow-up" size="sm" wire:loading.attr="disabled" wire:target="{{ $targets }}">{{ $label }}</x-filament::button>
    </div>
</form>
