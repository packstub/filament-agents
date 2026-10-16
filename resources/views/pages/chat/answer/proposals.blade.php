{{-- Under an answer: the changes it proposed (its write tool calls), each a card with the decision while it waits.
     Reads $message and $live. Part of pages/chat.blade.php, included with its variables; publish this file alone to
     change only this part. --}}
@foreach ($message['tools'] as $tool)
    @unless ($tool['readOnly'])
        {{-- A proposed change: one question with a decision while it waits (the most prominent thing on the page),
             the outcome in the same place once decided. The exact call folds under the question. --}}
        @php($state = $tool['pending'] ? 'pending' : ($tool['rejected'] ? 'rejected' : 'approved'))
        <div class="fi-chat-proposal fi-chat-proposal-{{ $state }}" x-data="{ open: false }" wire:key="proposal-{{ $message['id'] }}-{{ $tool['id'] }}" data-state="{{ $state }}" role="group" aria-label="{{ $tool['question'] }}">
            <div class="fi-chat-proposal-row">
                <x-filament::icon :icon="$tool['pending'] ? 'heroicon-m-question-mark-circle' : ($tool['rejected'] ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')" class="fi-chat-proposal-icon" />
                <div class="fi-chat-proposal-body">
                    <p class="fi-chat-proposal-question">{{ $tool['question'] }}</p>
                    @if ($tool['preview'] ?? [])
                        {{-- What the call would change (AgentTool::preview()): the value now, struck through, and the value after. --}}
                        <dl class="fi-chat-proposal-preview">
                            @foreach ($tool['preview'] as $row)
                                <dt>{{ $row['label'] }}</dt>
                                <dd>
                                    @if (array_key_exists('before', $row))
                                        <del>{{ \Packstub\Agents\Filament\Pages\Chat::previewValue($row['before']) }}</del>
                                    @endif
                                    @if (array_key_exists('before', $row) && array_key_exists('after', $row))
                                        <span aria-hidden="true">→</span>
                                    @endif
                                    @if (array_key_exists('after', $row))
                                        <ins>{{ \Packstub\Agents\Filament\Pages\Chat::previewValue($row['after']) }}</ins>
                                    @endif
                                </dd>
                            @endforeach
                        </dl>
                    @endif
                    <button type="button" class="fi-chat-proposal-call" x-on:click="open = ! open" x-bind:aria-expanded="open">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open }" />
                        <code>{{ $tool['tool'] }}</code>
                        <span>{{ trans_choice('{0} no arguments|{1} :count argument|[2,*] :count arguments', count($tool['arguments'])) }}</span>
                    </button>
                </div>
                <div class="fi-chat-proposal-decision">
                    @if ($tool['pending'] && $tool['held'] !== null)
                        {{-- Decided; it runs together with the other proposal of this answer once that one is decided too. --}}
                        <span class="fi-chat-proposal-outcome">{{ $tool['held'] ? __('Approved, once the other proposal is decided') : __('Rejected, once the other proposal is decided') }}</span>
                    @elseif ($tool['pending'])
                        <div class="fi-chat-proposal-actions" x-show="! busy">
                            {{-- Bound attributes: a Blade directive inside a component tag's attribute is not compiled. --}}
                            <x-filament::button size="sm" icon="heroicon-m-check" :x-on:click="'decide('.\Illuminate\Support\Js::from($tool['id']).', true)'">{{ __('Approve') }}</x-filament::button>
                            <x-filament::button size="sm" color="gray" outlined :x-on:click="'decide('.\Illuminate\Support\Js::from($tool['id']).', false)'">{{ __('Reject') }}</x-filament::button>
                        </div>
                        <span class="fi-chat-proposal-outcome" x-show="busy" x-cloak>{{ __('Deciding…') }}</span>
                    @else
                        <span class="fi-chat-proposal-outcome">{{ $tool['rejected'] ? __('Rejected') : __('Approved') }}</span>
                    @endif
                </div>
            </div>
            @if ($tool['pending'] && ($live['ended']['decision'] ?? false) && ($live['ended']['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::FAILED)
                {{-- The decision turn failed (the worker died, the provider or the history rejected it): say so here, where the buttons came back. --}}
                <p class="fi-chat-proposal-error" x-show="! busy">
                    <x-filament::icon icon="heroicon-m-exclamation-circle" />
                    <span>{{ __('The decision could not be applied.') }} {{ $live['ended']['error'] }}</span>
                </p>
            @endif
            <div class="fi-chat-proposal-details" x-show="open" x-collapse x-cloak>
                @if ($tool['arguments'])
                    <dl>
                        @foreach ($tool['arguments'] as $key => $value)
                            <dt>{{ \Illuminate\Support\Str::headline($key) }}</dt>
                            <dd>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                        @endforeach
                    </dl>
                @endif
                @if (! $tool['rejected'] && ($result = \Packstub\Agents\Support\AgentChat::resultText($tool['result'])) !== null)
                    <p class="fi-chat-proposal-label">{{ __('Result') }}</p>
                    <pre>{{ $result }}</pre>
                @endif
            </div>
        </div>
    @endunless
@endforeach
