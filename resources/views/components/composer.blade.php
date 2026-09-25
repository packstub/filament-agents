@props([
    'method' => 'send',
    'targets' => null,
    'placeholder' => null,
    'rows' => 1,
    'label' => null,
    'autofocus' => false,
    'stoppable' => true,
    'model' => null,
    'attachments' => null,
    'files' => [],
    'mentions' => false,
])
{{-- Slot `tools`: extra controls on the row, between the field and Stop (the chat page's context ring). --}}
@php
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Support\AgentChat;
    use Packstub\Agents\Support\AgentModels;

    $targets ??= $method;
    $placeholder ??= Agents::name().'…';
    $label ??= __('Send');
    $hint = __('Keep typing — the next question is sent when this answer is done.');
    $menu = AgentChat::modelMenu();
    $picked = $model ?? AgentModels::current();
    $pickedLabel = collect($menu)->collapse()->get($picked)['label'] ?? \Illuminate\Support\Str::headline($picked);
@endphp
{{-- One row: the question, then the tools, Stop while an answer runs, the model and a square Send. Driven by the page's
     agentChat Alpine component (resources/js/agent-chat.js): the field never locks — a question typed while an answer
     runs waits its turn on the server, and the placeholder says so meanwhile. Enter sends, Shift+Enter breaks the line,
     ↑ edits the last waiting question, Stop cuts the running answer short, @ offers records, / the starter questions,
     the paper clip attaches files. --}}
<form
    x-on:submit.prevent="submit()"
    {{ $attributes->class(['fi-agent-composer rounded-2xl border border-gray-950/10 bg-white shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:focus-within:border-primary-400 dark:focus-within:ring-primary-400']) }}
    x-on:dragover.prevent="dragging = true"
    x-on:dragleave.prevent="dragging = false"
    x-on:drop.prevent="dragging = false; dropFiles($event)"
    x-bind:class="{ 'fi-agent-composer-dragging': dragging }"
>
    <div class="flex items-center justify-between gap-3 px-4 pt-2.5 text-xs text-gray-500" x-show="editing" x-cloak>
        <span class="flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-m-pencil-square" class="h-3.5 w-3.5" />
            {{ __('Editing your last question — its answer will be replaced.') }}
        </span>
        <button type="button" class="hover:text-gray-700 hover:underline dark:hover:text-gray-200" x-on:click="cancelEdit()">{{ __('Cancel') }}</button>
    </div>

    @if ($files)
        {{-- The files picked for the next question, each removable until it is sent. --}}
        <div class="fi-agent-composer-files">
            @foreach ($files as $i => $file)
                <span class="fi-agent-composer-file" wire:key="composer-file-{{ $i }}">
                    @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                        <img src="{{ $file->temporaryUrl() }}" alt="" />
                    @else
                        <x-filament::icon icon="heroicon-m-document" class="h-4 w-4" />
                    @endif
                    <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                    <button type="button" wire:click="removeAttachment({{ $i }})" title="{{ __('Remove') }}" aria-label="{{ __('Remove :file', ['file' => $file->getClientOriginalName()]) }}"><x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" /></button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- The record picker: "@" opens it with the records the panel's resources know; ↑ ↓ Enter pick, Esc closes. --}}
    <div class="fi-agent-picker" x-show="picker.open" x-cloak role="listbox" aria-label="{{ __('Records') }}" x-ref="picker">
        <template x-if="picker.kind === 'mention' && picker.items.length === 0 && ! picker.loading">
            <p class="fi-agent-picker-empty" x-text="picker.query === '' ? @js(__('Type to search records.')) : @js(__('No record matches.'))"></p>
        </template>
        <template x-for="(item, i) in picker.items" :key="i">
            <button type="button" class="fi-agent-picker-item" role="option" x-bind:aria-selected="i === picker.index" x-bind:class="{ 'fi-agent-picker-item-active': i === picker.index }" x-on:mousedown.prevent="pick(i)" x-on:mousemove="picker.index = i">
                <span class="fi-agent-picker-label" x-text="item.label"></span>
                <span class="fi-agent-picker-detail" x-text="item.detail || ''"></span>
            </button>
        </template>
    </div>

    <div class="fi-agent-composer-row">
        @if ($attachments)
            <input type="file" x-ref="files" class="hidden" multiple accept="{{ $attachments['accept'] }}" wire:model="attachments" tabindex="-1" />
            <x-filament::icon-button icon="heroicon-m-paper-clip" color="gray" size="sm" :label="__('Attach a file')" :tooltip="__('Attach a file')" class="fi-agent-composer-attach" x-on:click="$refs.files.click()" />
        @endif
        <textarea
            x-ref="input"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            x-bind:placeholder="busy ? @js($hint) : @js($placeholder)"
            class="fi-agent-composer-input block w-full resize-none border-0 bg-transparent text-base text-gray-950 placeholder:text-gray-400 focus:outline-hidden focus:ring-0 dark:text-white"
            x-on:input="autosize(); typed()"
            x-on:keydown.enter="if (picker.open && picker.items.length) { $event.preventDefault(); pick(picker.index) } else if (! $event.shiftKey) { $event.preventDefault(); submit() }"
            x-on:keydown.up="picker.open ? (picker.index = Math.max(0, picker.index - 1), $event.preventDefault()) : recall($event)"
            x-on:keydown.down="if (picker.open) { picker.index = Math.min(picker.items.length - 1, picker.index + 1); $event.preventDefault() }"
            x-on:keydown.tab="if (picker.open && picker.items.length) { $event.preventDefault(); pick(picker.index) }"
            x-on:keydown.escape="picker.open ? closePicker() : (editing ? cancelEdit() : ($refs.input.value = '', autosize()))"
            x-on:blur="setTimeout(() => closePicker(), 150)"
            x-on:paste="pasteFiles($event)"
            aria-label="{{ $placeholder }}"
            aria-autocomplete="list"
            x-bind:aria-expanded="picker.open"
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
