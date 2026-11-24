{{-- A waiting proposal of a tool with HasApprovalForm: the form that corrects its arguments, as a Livewire component
     of its own inside the engine's chat (the chat's refresh leaves it alone). Reads $tool and $conversation. --}}
@livewire('packstub-agents.approval-form', ['conversation' => $conversation, 'call' => $tool['id']], key('approval-'.$tool['id']))
