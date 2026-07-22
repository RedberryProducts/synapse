import { PageHeader } from '@/components/PageHeader';

export default function Discovery() {
    return (
        <div className="p-8">
            <PageHeader
                title="Agents"
                subtitle="Auto-scanned from app/Agents/ on every request. Click a card to open the chat playground."
            />
            <div className="mt-8 rounded-lg border border-dashed border-border p-12 text-center text-muted-foreground">
                Agent cards will appear here.
            </div>
        </div>
    );
}
