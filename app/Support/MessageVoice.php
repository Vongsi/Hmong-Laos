<?php

namespace App\Support;

use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Voice messages in private chats. Phones and browsers record in different formats (WebM or Ogg with Opus, MP4 with
 * AAC), and not every phone plays every one, so each recording is converted with ffmpeg to one that every phone
 * plays: M4A (AAC, mono, 48 kbit/s: about 0.35 MB a minute), at most MAX_SECONDS long, without the recording's
 * details. A recording with no sound in it (a microphone that gave only silence) is refused. It's kept on the private
 * disk next to the conversation's photos (MessagePhoto), where only the conversation's members can play it
 * (MessagesController::voice).
 */
class MessageVoice
{
    const DISK = MessagePhoto::DISK;

    const FOLDER = MessagePhoto::FOLDER;

    /** The longest voice message: the recorder stops here (public/js/voice.js), and longer ones are cut. */
    const MAX_SECONDS = 300;

    const MIN_SECONDS = 1;

    /**
     * A recording whose loudest moment is quieter than this (decibels below full scale) has nobody speaking in it: a
     * microphone that gave only silence. Speech peaks around -20 to 0 dB; a silent microphone gives -91 dB.
     */
    const SILENT_DB = -50;

    /** The largest recording accepted, in kilobytes: five minutes at the rates browsers record at, with room to spare. */
    const MAX_KB = 12288;

    /** The recordings accepted, by the type their content shows, with ffmpeg's reader for each: only these. */
    const FORMATS = [
        'audio/webm' => 'matroska',
        'video/webm' => 'matroska',
        'audio/x-matroska' => 'matroska',
        'video/x-matroska' => 'matroska',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/mp4' => 'mov',
        'video/mp4' => 'mov',
        'audio/x-m4a' => 'mov',
        'audio/m4a' => 'mov',
        'video/quicktime' => 'mov',
        'audio/aac' => 'aac',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
    ];

    /**
     * Whether voice messages can be sent: ffmpeg is installed. Without it, there's no record button. Checked once an
     * hour, or every five minutes while it's missing (so a copy just installed is found soon).
     */
    public static function available(): bool
    {
        $known = Cache::get('message-voice:available');
        if (is_bool($known)) {
            return $known;
        }

        try {
            $found = Process::timeout(15)->run([config('services.ffmpeg.ffmpeg'), '-version'])->successful()
                && Process::timeout(15)->run([config('services.ffmpeg.ffprobe'), '-version'])->successful();
        } catch (Throwable) {
            $found = false;
        }
        Cache::put('message-voice:available', $found, $found ? 3600 : 300);

        return $found;
    }

    /**
     * Converts a recording and keeps it: ['path' => …, 'seconds' => …], or a translation key saying why not (too
     * short, or not a recording ffmpeg can read).
     */
    public static function store(UploadedFile $file, int $conversationId): array|string
    {
        $format = self::FORMATS[strtolower((string) $file->getMimeType())] ?? null;
        if (! $format) {
            return 'site.messages.voice_error';
        }

        $out = tempnam(sys_get_temp_dir(), 'voice');
        try {
            return self::convert($file, $format, $out, $conversationId);
        } catch (Throwable $e) {
            Log::warning("Messages: a voice message could not be converted: {$e->getMessage()}");

            return 'site.messages.voice_error';
        } finally {
            @unlink($out);
        }
    }

    private static function convert(UploadedFile $file, string $format, string $out, int $conversationId): array|string
    {
        $converted = Process::timeout(120)->run([
            config('services.ffmpeg.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
            // Only the uploaded file, read as the kind of recording its content shows: nothing from elsewhere.
            '-protocol_whitelist', 'file', '-f', $format, '-i', $file->getRealPath(),
            // Its sound only, in one channel.
            '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '48k',
            '-t', (string) self::MAX_SECONDS, '-map_metadata', '-1', '-movflags', '+faststart', '-f', 'mp4', '-y', $out,
        ]);
        clearstatcache(true, $out);
        if (! $converted->successful() || ! filesize($out)) {
            Log::info('Messages: a voice message could not be converted: '.Str::limit(trim($converted->errorOutput()), 300));

            return 'site.messages.voice_error';
        }

        $seconds = self::seconds($out);
        if ($seconds === null) {
            return 'site.messages.voice_error';
        }
        if ($seconds < self::MIN_SECONDS) {
            return 'site.messages.voice_too_short';
        }
        $loudest = self::loudest($out);
        if ($loudest !== null && $loudest < self::SILENT_DB) {
            return 'site.messages.voice_silent';
        }

        $path = self::FOLDER.'/'.$conversationId.'/'.Str::lower(Str::random(16)).'.m4a';
        Storage::disk(self::DISK)->put($path, file_get_contents($out));

        return ['path' => $path, 'seconds' => (int) min(self::MAX_SECONDS, round($seconds))];
    }

    public static function url(Message $message): ?string
    {
        return $message->voice ? route('messages.voice', $message->id, false) : null;
    }

    /** How long it is, as "0:42" or "3:05". */
    public static function length(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /** A voice message deleted for everyone (Messaging::unsend). The conversation's folder goes with its photos. */
    public static function forget(string $path): void
    {
        if (str_starts_with($path, self::FOLDER.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** The converted file's length in seconds, from ffprobe. */
    private static function seconds(string $file): ?float
    {
        $probe = Process::timeout(30)->run([config('services.ffmpeg.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $file]);
        $duration = trim($probe->output());

        return $probe->successful() && is_numeric($duration) ? (float) $duration : null;
    }

    /** How loud the converted file's loudest moment is, in decibels below full scale (ffmpeg's volumedetect), if known. */
    private static function loudest(string $file): ?float
    {
        $detect = Process::timeout(60)->run([config('services.ffmpeg.ffmpeg'), '-nostdin', '-hide_banner', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-']);

        return $detect->successful() && preg_match('/max_volume:\s*(-?[\d.]+|-inf) dB/', $detect->errorOutput(), $m)
            ? ($m[1] === '-inf' ? -INF : (float) $m[1])
            : null;
    }
}
