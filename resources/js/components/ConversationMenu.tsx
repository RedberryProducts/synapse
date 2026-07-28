import { MoreVertical, Plus, Trash2 } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';

/**
 * Conversation actions, in the header beside the Info button.
 *
 * The Figma screens never show these controls, so this reuses the `More` +
 * `Dropdown item` pattern the sidebar already uses for conversation rows —
 * which is also where Epic 6 will add Rename.
 */
export function ConversationMenu({
    onNew,
    onClear,
    canClear,
}: {
    onNew: () => void;
    onClear: () => void;
    canClear: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label="Conversation actions"
                data-testid="conversation-menu"
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
                <MoreVertical className="h-4 w-4" />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" side="bottom">
                <DropdownMenuItem onSelect={onNew}>
                    <Plus className="h-3.5 w-3.5" />
                    New conversation
                </DropdownMenuItem>

                {canClear && (
                    <DropdownMenuItem onSelect={onClear} className="text-destructive">
                        <Trash2 className="h-3.5 w-3.5" />
                        Clear conversation
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
