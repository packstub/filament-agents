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
    $placeholder ??= \Packstub\Agents\Facades\Agents::name().'…';
    $label ??= __('Send');
@endphp
{{-- One row: the question, the model picker and the send button. The field grows with the text and is cleared
     here, client-side: the server does the same, but Livewire only syncs it once the streamed response ends. --}}
<form
    x-data="{
        grow() { $refs.input.style.height = 'auto'; $refs.input.style.height = Math.min($refs.input.scrollHeight, 200) + 'px' },
        submit() { $wire.{{ $method }}(); $nextTick(() => { $refs.input.value = ''; this.grow() }) },
    }"
    x-init="grow()"
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer flex items-end gap-2 rounded-2xl border border-gray-200 bg-white p-2 ps-4 shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:focus-within:border-primary-400 dark:focus-within:ring-primary-400']) }}
>
    <textarea
        x-ref="input"
        wire:model="prompt"
        rows="{{ $rows }}"
        placeholder="{{ $placeholder }}"
        class="block min-h-10 w-full flex-1 resize-none self-center border-0 bg-transparent px-0 py-2 text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
        x-on:input="grow()"
        x-on:keydown.enter.prevent="if (!$event.shiftKey) submit()"
        wire:loading.attr="readonly"
        wire:target="{{ $targets }}"
        @if ($autofocus) autofocus @endif
    ></textarea>
    <x-filament::input.wrapper class="fi-agent-composer-model shrink-0">
        <x-filament::input.select wire:model="model">
            @foreach (\Packstub\Agents\Support\AgentModels::options() as $key => $modelLabel)
                <option value="{{ $key }}">{{ $modelLabel }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
    <x-filament::button type="submit" icon="heroicon-m-arrow-up" label-sr-only class="fi-agent-composer-send shrink-0" wire:loading.attr="disabled" wire:target="{{ $targets }}">{{ $label }}</x-filament::button>
</form>
