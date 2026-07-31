import { useState, type ReactNode } from 'react';
import { DeleteDialog } from '@/components/DeleteDialog';
import { RenameDialog } from '@/components/RenameDialog';
import { deleteConversation, renameConversation } from '@/lib/api';
import { conversationsChanged } from '@/lib/conversationsChanged';
import { forget } from '@/lib/lastConversation';
import type { ConversationSummary } from '@/types/conversation';

/**
 * Rename and delete, for anywhere a conversation is listed.
 *
 * History rows and the sidebar's recents offer the same two actions on the same
 * records, and a second copy of this logic is a second place for the two lists
 * to disagree about what a delete means. Owning it once also means neither
 * caller can forget the two things that are easy to miss: broadcasting
 * `conversationsChanged()` so every other list updates, and dropping the resume
 * pointer so a deleted conversation doesn't strand you on an empty playground.
 *
 * @param onChanged Called after a successful write, for a caller with its own
 *                  list to refresh. The sidebar doesn't need it — it listens for
 *                  the broadcast.
 */
export function useConversationActions(onChanged?: () => void): {
    askRename: (conversation: ConversationSummary) => void;
    askDelete: (conversation: ConversationSummary) => void;
    dialogs: ReactNode;
} {
    const [renaming, setRenaming] = useState<ConversationSummary | null>(null);
    const [deleting, setDeleting] = useState<ConversationSummary | null>(null);

    const rename = async (title: string) => {
        if (!renaming) {
            return;
        }

        await renameConversation(renaming.id, title).catch(() => null);

        setRenaming(null);
        onChanged?.();
        conversationsChanged();
    };

    const remove = async () => {
        if (!deleting) {
            return;
        }

        await deleteConversation(deleting.id).catch(() => null);

        // A remembered pointer to a deleted conversation would strand you on an
        // empty playground the next time you opened that agent.
        forget(deleting.id);

        setDeleting(null);
        onChanged?.();
        conversationsChanged();
    };

    return {
        askRename: setRenaming,
        askDelete: setDeleting,
        dialogs: (
            <>
                <RenameDialog
                    open={renaming !== null}
                    title={renaming?.title ?? ''}
                    onClose={() => setRenaming(null)}
                    onSave={(title) => void rename(title)}
                />

                <DeleteDialog
                    open={deleting !== null}
                    title={deleting?.title ?? ''}
                    onClose={() => setDeleting(null)}
                    onConfirm={() => void remove()}
                />
            </>
        ),
    };
}
