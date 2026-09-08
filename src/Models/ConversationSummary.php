<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/** The rolling summary of a long chat: stands in for the messages that no longer fit the history window. */
class ConversationSummary extends Model
{
    protected $table = 'agent_conversation_summaries';

    protected $guarded = [];
}
