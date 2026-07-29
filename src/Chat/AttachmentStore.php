<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

/**
 * Puts uploads on a disk and hands the SDK its own file objects.
 *
 * Files are never base64'd into the database: a 5MB PDF is ~6.7MB of text and
 * MySQL's `TEXT` caps at 64KB. What the message row stores is the SDK's own
 * serialization — `{type, name, path, disk}` — which is exactly the shape
 * `File::fromArray()` reads back, so rehydration follows the SDK rather than a
 * format of our own that would drift from it.
 */
class AttachmentStore
{
    /**
     * Where uploads live on the configured disk.
     */
    protected const string PREFIX = 'synapse';

    /**
     * Store the uploads and return the SDK file objects for this turn.
     *
     * @param  array<int, UploadedFile>  $uploads
     * @return array<int, File>
     */
    public function store(array $uploads): array
    {
        return array_map(fn (UploadedFile $upload): File => $this->storeOne($upload), $uploads);
    }

    /**
     * Rebuild the SDK file objects from a stored message's serialization.
     *
     * @param  array<int, mixed>  $attachments
     * @return array<int, File>
     */
    public function rehydrate(array $attachments): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $attachment): ?File => is_array($attachment) ? File::fromArray($attachment) : null,
            $attachments,
        )));
    }

    /**
     * The stored serialization of the given files.
     *
     * @param  array<int, File>  $files
     * @return array<int, array<string, mixed>>
     */
    public function toArray(array $files): array
    {
        return array_map(
            fn (File $file): array => method_exists($file, 'toArray') ? $file->toArray() : [],
            $files,
        );
    }

    protected function storeOne(UploadedFile $upload): File
    {
        $disk = $this->disk();

        $path = $upload->store(self::PREFIX, $disk);

        // `store()` can return false when the disk write fails.
        if (! is_string($path)) {
            $path = self::PREFIX.'/'.Str::uuid7();

            Storage::disk($disk)->put($path, (string) $upload->get());
        }

        $name = $upload->getClientOriginalName();

        // The SDK models exactly three kinds. Anything that is not obviously an
        // image or audio file is a document — and there is deliberately no
        // allowlist, so an unsupported type reaches the provider and comes back
        // as an error card rather than being blocked here.
        $file = match (true) {
            str_starts_with((string) $upload->getMimeType(), 'image/') => new StoredImage($path, $disk),
            str_starts_with((string) $upload->getMimeType(), 'audio/') => new StoredAudio($path, $disk),
            default => new StoredDocument($path, $disk),
        };

        return $file->as($name);
    }

    protected function disk(): string
    {
        return (string) config('synapse.storage.attachments_disk', 'local');
    }
}
