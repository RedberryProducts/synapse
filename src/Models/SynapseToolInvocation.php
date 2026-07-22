<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string|null $message_id
 * @property string $invocation_id
 * @property string $tool_invocation_id
 * @property string $type
 * @property string $name
 * @property string $status
 */
class SynapseToolInvocation extends SynapseModel
{
    protected $table = 'synapse_tool_invocations';

    /**
     * Invocations carry only a created_at column.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SynapseConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SynapseConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<SynapseMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SynapseMessage::class, 'message_id');
    }
}
