import { useRef, useState } from 'react';
import { ArrowRight, AudioLines, Image, Paperclip, Plus, Upload } from 'lucide-react';
import { Button } from '@/elements/Button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/elements/DropdownMenu';
import { FileChip } from '@/elements/FileChip';
import { Textarea } from '@/elements/Textarea';
import { cn } from '@/lib/utils';
import { ModelSelector } from './ModelSelector';
import type { ModelOption } from '@/types/agent';

/**
 * The message composer — Figma `Chat Input`, all five states.
 *
 * The attach menu offers Image, Audio and Attach File, matching the three kinds
 * the SDK models. Figma's third item reads "Image & Video", but the SDK has no
 * video type and no provider accepts video on a text call — see
 * DESIGN_FEEDBACK #8. A video dropped through "Attach File" still uploads and
 * still reaches the provider; its rejection is the honest answer.
 */
const pickers = [
    { label: 'Attach File', icon: Paperclip, accept: '' },
    { label: 'Audio', icon: AudioLines, accept: 'audio/*' },
    { label: 'Image', icon: Image, accept: 'image/*' },
];

export function ChatComposer({
    onSend,
    models = [],
    model,
    onModelChange,
    disabled = false,
    autoFocus = false,
}: {
    onSend: (message: string, files: File[]) => void;
    models?: ModelOption[];
    model?: string | null;
    onModelChange?: (model: string | null) => void;
    disabled?: boolean;
    autoFocus?: boolean;
}) {
    const [message, setMessage] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    // Drag events fire per child element, so a plain boolean would flicker as
    // the pointer crosses the textarea. Counting enter/leave pairs doesn't.
    const dragDepth = useRef(0);

    const submit = () => {
        if (disabled || (message.trim() === '' && files.length === 0)) {
            return;
        }

        onSend(message, files);
        setMessage('');
        setFiles([]);
    };

    const pick = (accept: string) => {
        if (input.current) {
            input.current.accept = accept;
            input.current.click();
        }
    };

    const add = (added: FileList | null) => {
        if (added && added.length > 0) {
            setFiles((current) => [...current, ...Array.from(added)]);
        }
    };

    return (
        <div
            data-testid="chat-composer"
            onDragEnter={(event) => {
                event.preventDefault();
                dragDepth.current += 1;
                setDragging(true);
            }}
            onDragOver={(event) => event.preventDefault()}
            onDragLeave={() => {
                dragDepth.current -= 1;

                if (dragDepth.current <= 0) {
                    setDragging(false);
                }
            }}
            onDrop={(event) => {
                event.preventDefault();
                dragDepth.current = 0;
                setDragging(false);
                add(event.dataTransfer.files);
            }}
            className={cn(
                'flex flex-col gap-3 rounded-xl border bg-card px-4 py-3',
                dragging ? 'border-dashed border-primary' : 'border-border',
            )}
        >
            <input
                ref={input}
                type="file"
                multiple
                hidden
                aria-hidden="true"
                onChange={(event) => {
                    add(event.target.files);
                    event.target.value = '';
                }}
            />

            {dragging ? (
                <div
                    data-testid="drop-zone"
                    className="flex flex-col items-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <Upload className="h-6 w-6" />
                    Drop your file here
                </div>
            ) : (
                <Textarea
                    value={message}
                    autoFocus={autoFocus}
                    placeholder="Type your message here..."
                    aria-label="Message"
                    data-testid="composer-input"
                    onChange={(event) => setMessage(event.target.value)}
                    onKeyDown={(event) => {
                        // Enter sends; Shift+Enter is a newline — the convention
                        // every chat surface a developer already uses follows.
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            submit();
                        }
                    }}
                />
            )}

            <div className="flex items-center gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger
                        aria-label="Attach a file"
                        className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <Plus className="h-4 w-4" />
                    </DropdownMenuTrigger>

                    <DropdownMenuContent side="top" className="w-44">
                        {pickers.map(({ label, icon: Icon, accept }) => (
                            <DropdownMenuItem key={label} onSelect={() => pick(accept)}>
                                <Icon className="h-3.5 w-3.5" />
                                {label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>

                <div className="flex min-w-0 flex-1 flex-wrap gap-2">
                    {files.map((file, index) => (
                        <FileChip
                            key={`${file.name}-${index}`}
                            name={file.name}
                            kind={
                                file.type.startsWith('image/')
                                    ? 'image'
                                    : file.type.startsWith('audio/')
                                      ? 'audio'
                                      : 'document'
                            }
                            onRemove={() =>
                                setFiles((current) => current.filter((_, at) => at !== index))
                            }
                        />
                    ))}
                </div>

                <ModelSelector
                    models={models}
                    selected={model ?? null}
                    onSelect={(next) => onModelChange?.(next)}
                />

                <Button
                    size="sm"
                    onClick={submit}
                    disabled={disabled || (message.trim() === '' && files.length === 0)}
                    aria-label="Send message"
                >
                    Send
                    <ArrowRight className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}
