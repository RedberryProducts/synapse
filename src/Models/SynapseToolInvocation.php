<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string|null $message_id
 * @property string $invocation_id
 * @property string $tool_invocation_id
 * @property string $type
 * @property string $name
 * @property string $status
 * @property string|null $provider_status
 * @property string|null $error
 * @property array<string, mixed>|null $arguments
 * @property mixed $result
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
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
        // A tool handler returns a string, so a result is whatever the tool
        // produced — often JSON, but just as often prose. `json` round-trips
        // both without pretending the payload is always an array.
        'result' => 'json',
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
