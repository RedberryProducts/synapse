<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string|null $content
 * @property array<int, mixed> $attachments
 * @property array<int, mixed> $tool_calls
 * @property array<int, mixed> $tool_results
 * @property array<string, mixed> $usage
 * @property array<string, mixed> $meta
 * @property array<string, mixed> $metadata
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $duration_ms
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
