<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Attributes\Strict;

/**
 * The `#[Strict]` flavour of the history decorator.
 *
 * `Strict::isAppliedTo()` reflects the attribute off the agent instance and has
 * no method fallback, so it is the one setting the decorator cannot forward.
 * A second annotated class is the only way to carry it across the wrap;
 * `SynapseConversationalAgent::for()` picks between the two.
 */
#[Strict]
final class StrictSynapseConversationalAgent extends SynapseConversationalAgent
{
    //
}
