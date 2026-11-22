<?php

namespace Tests\Feature;

use App\Livewire\Messages;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageVoice;
use App\Support\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    /** How long ffprobe says the converted recording is. */
    private string $length = '12.4';

    /** Whether ffmpeg manages to convert it. */
    private bool $converts = true;

    /** How loud its loudest moment is, in dB, as ffmpeg's volumedetect says. */
    private string $loudest = '-3.2';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MessageVoice::DISK);
        // ffmpeg writes its M4A file (its last argument), or measures how loud it is; ffprobe tells its length.
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            if (str_contains($command[0], 'ffprobe')) {
                return Process::result(output: $this->length."\n");
            }
            if (in_array('volumedetect', $command, true)) {
                return Process::result(errorOutput: "[Parsed_volumedetect_0 @ 0x1] mean_volume: -30.1 dB\n[Parsed_volumedetect_0 @ 0x1] max_volume: {$this->loudest} dB\n");
            }
            if (! $this->converts) {
                return Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1);
            }
            file_put_contents(end($command), 'converted m4a');

            return Process::result();
        });
    }

    private function member(string $name): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid().'@example.com', 'password' => 'secret-pass']);
        $user->forceFill(['show_in_directory' => true, 'accepts_messages' => true])->save();

        return $user;
    }

    private function recording(string $mime = 'video/webm'): UploadedFile
    {
        return UploadedFile::fake()->create('voice.webm', 40, $mime);
    }

    public function test_a_recording_is_converted_and_kept_as_a_voice_message(): void
    {
        [$mai, $tou] = [$this->member('Mai'), $this->member('Tou')];

        $message = Messaging::send($mai, $tou, 'ignored with a voice message', null, $this->recording());

        $this->assertInstanceOf(Message::class, $message);
        $this->assertNull($message->body);
        $this->assertSame(12, $message->voice_seconds);
        $this->assertMatchesRegularExpression('#^messages/'.$message->conversation_id.'/[a-z0-9]{16}\.m4a$#', $message->voice);
        Storage::disk(MessageVoice::DISK)->assertExists($message->voice);
        // Read only as the kind of recording it is, from the uploaded file, and turned into mono AAC.
        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command[0], 'ffmpeg')
            && array_slice($process->command, 5, 4) === ['-protocol_whitelist', 'file', '-f', 'matroska']
            && in_array('aac', $process->command, true) && in_array((string) MessageVoice::MAX_SECONDS, $process->command, true));
    }

    public function test_recordings_that_are_too_short_unreadable_silent_or_not_sound_are_refused(): void
    {
        [$mai, $tou] = [$this->member('Mai'), $this->member('Tou')];

        $this->length = '0.4';
        $this->assertSame('site.messages.voice_too_short', Messaging::send($mai, $tou, '', null, $this->recording()));

        $this->length = '12.4';
        $this->converts = false;
        $this->assertSame('site.messages.voice_error', Messaging::send($mai, $tou, '', null, $this->recording()));

        $this->converts = true;
        $this->assertSame('site.messages.voice_error', Messaging::send($mai, $tou, '', null, $this->recording('text/plain')));

        // A microphone that gave only silence.
        $this->loudest = '-91.0';
        $this->assertSame('site.messages.voice_silent', Messaging::send($mai, $tou, '', null, $this->recording()));
        $this->loudest = '-inf';
        $this->assertSame('site.messages.voice_silent', Messaging::send($mai, $tou, '', null, $this->recording()));

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk(MessageVoice::DISK)->allFiles());
    }

    public function test_a_recording_whose_message_could_not_be_saved_is_not_kept(): void
    {
        [$mai, $tou] = [$this->member('Mai'), $this->member('Tou')];
        Message::creating(fn () => throw new \RuntimeException('The database is down.'));

        try {
            Messaging::send($mai, $tou, '', null, $this->recording());
            $this->fail('The message was saved.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The database is down.', $e->getMessage());
        }

        $this->assertSame([], Storage::disk(MessageVoice::DISK)->allFiles());
    }

    public function test_deleting_it_for_everyone_deletes_the_recording(): void
    {
        [$mai, $tou] = [$this->member('Mai'), $this->member('Tou')];
        // As the Messages page has it: read back, with the kind the database gave it.
        $message = Messaging::send($mai, $tou, '', null, $this->recording())->fresh();
        $path = $message->voice;

        $this->assertSame('site.messages.cannot_change', Messaging::edit($message, $mai, 'Some text'));
        $this->assertNull(Messaging::unsend($message, $mai));

        Storage::disk(MessageVoice::DISK)->assertMissing($path);
        $this->assertNull($message->fresh()->voice);
        $this->assertNotNull($message->fresh()->deleted_at);
    }

    public function test_only_the_chats_members_can_play_it_in_parts(): void
    {
        [$mai, $tou, $stranger] = [$this->member('Mai'), $this->member('Tou'), $this->member('Nou')];
        $message = Messaging::send($mai, $tou, '', null, $this->recording());
        $url = route('messages.voice', $message->id);

        $this->actingAs($tou)->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mp4');
        $this->actingAs($tou)->withHeader('Range', 'bytes=0-3')->get($url)->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/13');
        $this->actingAs($stranger)->get($url)->assertNotFound();
    }

    public function test_the_chat_list_and_the_chat_show_it(): void
    {
        [$mai, $tou] = [$this->member('Mai'), $this->member('Tou')];
        Cache::put('message-voice:available', true);
        $this->actingAs($mai);

        Livewire::test(Messages::class, ['to' => $tou->id])
            ->set('voice', UploadedFile::fake()->createWithContent('voice.webm', $this->webm()))
            ->call('sendVoice')
            ->assertHasNoErrors()
            ->assertSee('data-voice-player', false)
            ->assertSee('0:12')
            ->assertSee('🎤 '.__('site.messages.voice').' · 0:12');

        $this->assertNotNull(Message::first()->voice);
    }

    public function test_without_ffmpeg_there_is_no_record_button(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        $this->assertFalse(MessageVoice::available());
        $this->assertFalse(Cache::get('message-voice:available'));
    }

    public function test_lengths(): void
    {
        $this->assertSame(['0:00', '0:09', '1:05', '5:00'], array_map(fn ($s) => MessageVoice::length($s), [null, 9, 65, 300]));
    }

    /** The start of a WebM file, as browsers record: enough for its type to be recognised. */
    private function webm(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02"
            .str_repeat("\0", 2048);
    }
}
