import { PageHeader } from '@/components/PageHeader';

export default function History() {
    return (
        <div className="p-8">
            <PageHeader
                title="History"
                subtitle="Every conversation you've had in the playground. Click a row to reopen it."
            />
            <div className="mt-8 rounded-lg border border-dashed border-border p-12 text-center text-muted-foreground">
                Past conversations will appear here.
            </div>
        </div>
    );
}
