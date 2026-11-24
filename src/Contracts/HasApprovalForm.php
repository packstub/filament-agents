<?php

namespace Packstub\FilamentAgents\Contracts;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;

/**
 * A write tool whose proposal the person can correct before approving: the approval card shows this form, filled
 * from the proposed arguments, in place of the Approve and Reject buttons. Approve validates it with the components'
 * own rules (errors show under the fields and nothing runs), then the tool runs with the form's state over the
 * proposed arguments, key by key. Only the panel's chat draws it; elsewhere (a plain page, a typed "yes", MCP) the
 * call is approved as proposed.
 */
interface HasApprovalForm
{
    /**
     * Filament form components, one per argument the person may correct, named after the argument.
     *
     * @param  array<string, mixed>  $arguments  the call as the model proposed it
     * @return array<Component|Field>
     */
    public function approvalForm(array $arguments): array;
}
