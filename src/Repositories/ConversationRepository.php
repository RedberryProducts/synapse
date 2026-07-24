<?php

namespace Redberry\Synapse\Repositories;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;

/**
 * Owns cascade deletion for Synapse conversations. Cascade happens here (not
 * via DB foreign keys) so it works on sqlite, whose FK pragma is off by
 * default — see PRD → Database Schema.
 */
class ConversationRepository
{
    /**
     * Delete conversations last updated before the given moment.
     */
    public function prune(CarbonInterface $before): int
    {
        $ids = SynapseConversation::query()
            ->where('updated_at', '<', $before)
            ->pluck('id')
            ->all();

        return $this->deleteConversations($ids);
    }

    /**
     * Delete every conversation.
     */
    public function clear(): int
    {
        return $this->deleteConversations(
            SynapseConversation::query()->pluck('id')->all()
        );
    }

    /**
     * Delete the given conversations along with their messages, tool
     * invocations, and stored attachment files.
     *
     * @param  array<int, string>  $ids
     * @return int Number of conversations deleted.
     */
    public function deleteConversations(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $this->deleteAttachmentFiles($ids);

        SynapseToolInvocation::query()->whereIn('conversation_id', $ids)->delete();
        SynapseMessage::query()->whereIn('conversation_id', $ids)->delete();

        return SynapseConversation::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Remove uploaded attachment files belonging to the given conversations.
     *
     * @param  array<int, string>  $conversationIds
     */
    protected function deleteAttachmentFiles(array $conversationIds): void
    {
        $fallbackDisk = config('synapse.storage.attachments_disk');

        SynapseMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('role', 'user')
            ->get(['id', 'attachments'])
            ->each(function (SynapseMessage $message) use ($fallbackDisk): void {
                foreach ((array) $message->attachments as $attachment) {
                    if (! is_array($attachment) || empty($attachment['path'])) {
                        continue;
                    }

                    Storage::disk($attachment['disk'] ?? $fallbackDisk)->delete($attachment['path']);
                }
            });
    }
}
