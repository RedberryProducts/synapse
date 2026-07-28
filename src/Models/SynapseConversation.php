<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $agent_class
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SynapseConversation extends SynapseModel
{
    protected $table = 'synapse_conversations';

    protected $guarded = [];

    /**
     * @return HasMany<SynapseMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SynapseMessage::class, 'conversation_id');
    }

    /**
     * @return HasMany<SynapseToolInvocation, $this>
     */
    public function toolInvocations(): HasMany
    {
        return $this->hasMany(SynapseToolInvocation::class, 'conversation_id');
    }
}
