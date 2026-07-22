<?php

namespace Redberry\Synapse\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Model;

abstract class SynapseModel extends Model
{
    use HasVersion7Uuids;

    /**
     * uuid7 string primary keys.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Resolve the configured Synapse connection (app default when null).
     */
    public function getConnectionName(): ?string
    {
        return config('synapse.storage.connection') ?: config('database.default');
    }
}
