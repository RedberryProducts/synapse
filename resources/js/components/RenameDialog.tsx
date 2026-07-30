import { useEffect, useState } from 'react';
import { Button } from '@/elements/Button';
import { Dialog } from '@/elements/Dialog';
import { Input } from '@/elements/Input';

/**
 * Rename a conversation — Figma `Modal/Rename`.
 *
 * Titles are yours to write. Synapse derives the first one from your opening
 * message and never asks a model to invent one, here or anywhere else.
 */
export function RenameDialog({
    open,
    title,
    onClose,
    onSave,
}: {
    open: boolean;
    title: string;
    onClose: () => void;
    onSave: (title: string) => void;
}) {
    const [value, setValue] = useState(title);

    // Reopening on a different row must not show the previous row's title.
    useEffect(() => setValue(title), [title, open]);

    const save = () => {
        if (value.trim() !== '') {
            onSave(value.trim());
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            testId="rename-dialog"
            title="Rename conversation"
            description="Give this conversation a name that helps you recognize it"
            footer={
                <>
                    <Button onClick={save} disabled={value.trim() === ''}>
                        Save
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </>
            }
        >
            <Input
                value={value}
                autoFocus
                placeholder="Conversation Name..."
                aria-label="Conversation name"
                onChange={(event) => setValue(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        save();
                    }
                }}
            />
        </Dialog>
    );
}
