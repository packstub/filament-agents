{{-- A show-table answer on a page outside the panel: the same rows, read-only, drawn by the chat's own view. Reads
     $table (resource, filters, title, limit). Nothing when the rows cannot be read for this person here. --}}
@if ($rows = \Packstub\FilamentAgents\Support\PlainTable::for($table))
    @include('packstub-agents-chat::embeds.rows', $rows)
@endif
