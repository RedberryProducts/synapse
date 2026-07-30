import { Button } from '@/elements/Button';
import { Dialog } from '@/elements/Dialog';

/**
 * Confirm deleting a conversation — Figma `Modal/Delete`.
 *
 * Deletion takes the messages, the tool rows and the stored attachment files
 * with it, which is why it asks first.
 */
export function DeleteDialog({
    open,
    title,
    onClose,
    onConfirm,
}: {
    open: boolean;
    title: string;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={open}
            onClose={onClose}
            testId="delete-dialog"
            title="Delete conversation"
            description="This action cannot be undone. The messages, tool calls and any attached files will be removed."
            footer={
                <>
                    <Button onClick={onConfirm} aria-label="Confirm delete">
                        Delete
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </>
            }
        >
            <p className="truncate text-sm text-muted-foreground">{title}</p>
        </Dialog>
    );
}
