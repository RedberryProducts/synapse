<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Messages\UserMessage;
use Redberry\Synapse\Chat\MessageHistory;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Synapse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workbench\App\Agents\SupportAgent;
use Workbench\App\Agents\WeatherAgent;

beforeEach(function () {
    Synapse::auth(fn (): bool => true);

    Storage::fake('local');

    config(['synapse.discovery.paths' => [dirname(__DIR__, 3).'/workbench/app/Agents']]);
});

function sendWithFile(string $slug, string $message, UploadedFile $file, ?string $conversationId = null)
{
    $response = test()->post("/synapse/api/chat/{$slug}/send", array_filter([
        'message' => $message,
        'conversation_id' => $conversationId,
        'attachments' => [$file],
    ]));

    if ($response->baseResponse instanceof StreamedResponse) {
        $response->streamedContent();
    }

    return $response;
}

it('stores an upload on the configured disk under a synapse prefix', function () {
    fakeAgent(WeatherAgent::class, ['A cloudy sky.']);

    sendWithFile(
        'workbench.app.agents.weather-agent',
        'What is in this picture?',
        UploadedFile::fake()->image('sky.png'),
    );

    $stored = Storage::disk('local')->allFiles('synapse');

    expect($stored)->toHaveCount(1);
});

it('records the SDK own serialization on the user row', function () {
    fakeAgent(WeatherAgent::class, ['A cloudy sky.']);

    sendWithFile(
        'workbench.app.agents.weather-agent',
        'What is in this picture?',
        UploadedFile::fake()->image('sky.png'),
    );

    $attachment = SynapseMessage::query()->where('role', 'user')->sole()->attachments[0];

    // Exactly the shape File::fromArray() reads back — which is why the row
    // stores this rather than a Synapse-shaped record of its own.
    expect($attachment['type'])->toBe('stored-image')
        ->and($attachment['name'])->toBe('sky.png')
        ->and($attachment['path'])->toStartWith('synapse/')
        ->and($attachment['disk'])->toBe('local');
});

it('picks the file kind the SDK models from the upload type', function () {
    fakeAgent(WeatherAgent::class, fn (): string => 'Noted.');

    $kinds = [];

    foreach ([
        ['file' => UploadedFile::fake()->image('a.png'), 'expected' => 'stored-image'],
        ['file' => UploadedFile::fake()->create('a.mp3', 8, 'audio/mpeg'), 'expected' => 'stored-audio'],
        ['file' => UploadedFile::fake()->create('a.pdf', 8, 'application/pdf'), 'expected' => 'stored-document'],
    ] as $case) {
        SynapseMessage::query()->delete();

        sendWithFile('workbench.app.agents.weather-agent', 'Look', $case['file']);

        $kinds[] = [
            SynapseMessage::query()->where('role', 'user')->sole()->attachments[0]['type'],
            $case['expected'],
        ];
    }

    foreach ($kinds as [$actual, $expected]) {
        expect($actual)->toBe($expected);
    }
});

it('gives a later turn the file the earlier turn carried', function () {
    // The failure this guards is invisible: without rehydration the model is
    // asked about a picture it can no longer see, and answers anyway.
    fakeAgent(SupportAgent::class, fn (): string => 'Noted.');

    $conversationId = chatConversationId(sendWithFile(
        'workbench.app.agents.support-agent',
        'What is in this picture?',
        UploadedFile::fake()->image('sky.png'),
    ));

    $history = app(MessageHistory::class)->for($conversationId);

    expect($history[0])->toBeInstanceOf(UserMessage::class)
        ->and($history[0]->attachments)->toHaveCount(1)
        ->and($history[0]->attachments->first()->name())->toBe('sky.png');
});

it('leaves a plain turn without an attachment wrapper', function () {
    fakeAgent(SupportAgent::class, ['Fine.']);

    $conversationId = chatConversationId(
        sendMessage('workbench.app.agents.support-agent', 'No files here')
    );

    expect(app(MessageHistory::class)->for($conversationId)[0])
        ->not->toBeInstanceOf(UserMessage::class);
});

it('deletes the stored files when the conversation goes', function () {
    fakeAgent(WeatherAgent::class, ['A cloudy sky.']);

    $conversationId = chatConversationId(sendWithFile(
        'workbench.app.agents.weather-agent',
        'What is in this picture?',
        UploadedFile::fake()->image('sky.png'),
    ));

    $path = SynapseMessage::query()->where('role', 'user')->sole()->attachments[0]['path'];

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    test()->deleteJson("/synapse/api/conversations/{$conversationId}")->assertNoContent();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('serves an attachment back without exposing where it lives', function () {
    fakeAgent(WeatherAgent::class, ['A cloudy sky.']);

    $conversationId = chatConversationId(sendWithFile(
        'workbench.app.agents.weather-agent',
        'What is in this picture?',
        UploadedFile::fake()->image('sky.png'),
    ));

    $attachment = test()->getJson("/synapse/api/conversations/{$conversationId}")
        ->json('messages.0.attachments.0');

    expect($attachment['name'])->toBe('sky.png')
        ->and($attachment['type'])->toBe('stored-image')
        ->and($attachment)->not->toHaveKey('path')
        ->and($attachment)->not->toHaveKey('disk');

    test()->get($attachment['url'])->assertOk();
});

it('404s for an attachment that is not there', function () {
    fakeAgent(SupportAgent::class, ['Fine.']);

    sendMessage('workbench.app.agents.support-agent', 'No files here');

    $message = SynapseMessage::query()->where('role', 'user')->sole();

    test()->get("/synapse/api/attachments/{$message->id}/0")->assertNotFound();
    test()->get('/synapse/api/attachments/nope/0')->assertNotFound();
});

it('keeps attachments behind the gate', function () {
    Synapse::auth(fn (): bool => false);

    test()->get('/synapse/api/attachments/anything/0')->assertForbidden();
});
