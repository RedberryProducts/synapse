<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Redberry\Synapse\Models\SynapseMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentsController
{
    /**
     * Serve one of a message's stored attachments back to the thread.
     *
     * The path is read from the message row, never from the request, so there
     * is no traversal to defend against — a caller can only ask for an
     * attachment that a stored message already claims.
     */
    public function show(string $message, int $index): StreamedResponse
    {
        $row = SynapseMessage::query()->find($message);

        abort_if($row === null, 404, 'Message not found.');

        $attachment = $row->attachments[$index] ?? null;

        abort_if(! is_array($attachment) || ! isset($attachment['path']), 404, 'Attachment not found.');

        $disk = Storage::disk($attachment['disk'] ?? config('synapse.storage.attachments_disk'));

        abort_unless($disk->exists($attachment['path']), 404, 'Attachment file is gone.');

        return $disk->response(
            $attachment['path'],
            $attachment['name'] ?? basename((string) $attachment['path']),
            ['Content-Type' => $disk->mimeType($attachment['path']) ?: 'application/octet-stream'],
            'inline',
        );
    }
}
