<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;

/** Thumbs up / down on one assistant message (next to the conversations). */
class AgentMessageFeedback extends Model
{
    protected $table = 'agent_message_feedback';

    protected $guarded = [];
}
