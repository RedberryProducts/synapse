import { useParams } from 'react-router-dom';
import { PageHeader } from '@/components/PageHeader';

export default function Playground() {
    const { agent } = useParams();

    return (
        <div className="p-8">
            <PageHeader
                title={agent ?? 'Playground'}
                subtitle="Chat with your agent and inspect tool calls, tokens, and reasoning."
            />
            <div className="mt-8 rounded-lg border border-dashed border-border p-12 text-center text-muted-foreground">
                The chat playground will appear here.
            </div>
        </div>
    );
}
