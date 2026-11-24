{{-- A proposal's corrections (HasApprovalForm): the tool's fields filled from the proposed call, Approve validates
     them and decides with what they hold; Reject is the chat's own decision, made without the form. --}}
<form wire:submit="approve" class="fi-chat-approval-form">
    {{ $this->form }}

    <div class="fi-chat-proposal-actions fi-chat-approval-form-actions">
        <x-filament::button type="submit" size="sm" icon="heroicon-m-check" wire:target="approve">
            {{ __('Approve') }}
        </x-filament::button>
        <x-filament::button type="button" size="sm" color="gray" outlined :x-on:click="'decide('.\Illuminate\Support\Js::from($call).', false)'">
            {{ __('Reject') }}
        </x-filament::button>
    </div>
</form>
