<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string|null $content
 */
class SynapseMessage extends SynapseModel
{
    protected $table = 'synapse_messages';

    /**
     * Messages carry only a created_at column.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'attachments' => 'array',
        'tool_calls' => 'array',
        'tool_results' => 'array',
        'usage' => 'array',
        'meta' => 'array',
        'metadata' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<SynapseConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SynapseConversation::class, 'conversation_id');
    }
}
