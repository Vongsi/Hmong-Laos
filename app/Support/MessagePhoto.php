<?php

namespace App\Support;

use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Photos in private messages. Saved again as a JPEG of at most 1600 px (turned the right way up, without the
 * camera's location and other details) on the private disk, where only the conversation's two members can open
 * them (MessagesController::photo). No watermark: they aren't public.
 */
class MessagePhoto
{
    const DISK = 'local';

    const FOLDER = 'messages';

    const SIZE = 1600;

    public static function store(UploadedFile $file, int $conversationId): string
    {
        $image = ImageManager::usingDriver(Driver::class, autoOrientation: true, strip: true)->decodePath($file->getRealPath());
        $image->scaleDown(self::SIZE, self::SIZE);
        $path = self::FOLDER.'/'.$conversationId.'/'.Str::lower(Str::random(16)).'.jpg';
        Storage::disk(self::DISK)->put($path, $image->encode(new JpegEncoder(quality: 82, strip: true))->toString());

        return $path;
    }

    public static function url(Message $message): ?string
    {
        return $message->photo ? route('messages.photo', $message->id, false) : null;
    }

    /** A photo deleted for everyone (Messaging::unsend). */
    public static function forget(string $path): void
    {
        if (str_starts_with($path, self::FOLDER.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function forgetConversation(int $conversationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::FOLDER.'/'.$conversationId);
    }
}
