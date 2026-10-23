<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string|null $content
 * @property array<int, mixed> $attachments
 * @property array<int, mixed>|null $steps
 * @property array<int, mixed>|null $parts
 * @property string $status
 * @property array<string, mixed> $usage
 * @property array<string, mixed> $meta
 * @property array<string, mixed> $metadata
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
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
        'steps' => 'array',
        'parts' => 'array',
        'usage' => 'array',
        'meta' => 'array',
        'metadata' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<SynapseConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SynapseConversation::class, 'conversation_id');
    }

    /** @return HasMany<SynapseToolInvocation, $this> */
    public function toolInvocations(): HasMany
    {
        return $this->hasMany(SynapseToolInvocation::class, 'message_id');
    }
}
