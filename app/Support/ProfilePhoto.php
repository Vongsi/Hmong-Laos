<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Facades\Asset;
use Statamic\Facades\Image;

/**
 * Members' profile photos. Cropped to a square in the browser (public/js/photo-crop.js), then made into a fresh
 * 512×512 JPEG here, which also leaves out what the camera wrote into the file (where it was taken, the phone).
 * Kept as a Statamic asset in assets/avatars and in the users' "avatar" column, so the control panel shows it too.
 * Shown on My account and the Members page, at the size each needs through Statamic's image resizing (Glide).
 */
class ProfilePhoto
{
    const FOLDER = 'avatars';

    const SIZE = 512;

    /** Replaces the member's photo and returns its asset path. Throws when the file isn't a picture. */
    public static function store(User $user, UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) $file->get());
        if (! $source) {
            throw new RuntimeException('Not a picture.');
        }

        // The middle square, should the browser not have cropped it; transparent pictures on white.
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $photo = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 255, 255, 255));
        imagecopyresampled($photo, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($photo, null, 86);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($photo);

        $path = self::FOLDER.'/'.$user->id.'-'.Str::lower(Str::random(10)).'.jpg';
        $asset = Asset::make()->container('assets')->path($path);
        $asset->disk()->put($path, $jpeg);
        $asset->save();

        self::forget($user->avatar, $user->id);
        $user->forceFill(['avatar' => $path])->save();

        return $path;
    }

    public static function remove(User $user): void
    {
        self::forget($user->avatar, $user->id);
        $user->forceFill(['avatar' => null])->save();
    }

    /** A square version, $size pixels wide (callers ask twice what they show, for sharp phone screens). */
    public static function url(?string $path, int $size): ?string
    {
        return $path ? Image::manipulate('assets::'.$path, ['w' => $size, 'h' => $size, 'fit' => 'crop', 'q' => 80]) : null;
    }

    /** Deletes a photo this site made for this member; never one the team chose in the control panel. */
    public static function forget(?string $path, $userId): void
    {
        if ($path && str_starts_with($path, self::FOLDER.'/'.$userId.'-')) {
            Asset::find('assets::'.$path)?->delete();
        }
    }
}
