<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use GdImage;

/**
 * Deterministic image pipeline on GD (no shell-out):
 * validation (real MIME from bytes, extension match, dimension / pixel /
 * byte limits, script-in-image markers) → re-encode (drops EXIF/XMP and any
 * appended payload) → resize / crop → WebP / AVIF (when the GD build has
 * it) / thumbnail, plus a 64-bit difference hash for near-duplicates.
 */
final class ImageProcessor
{
    public const MIME_EXT = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
    ];

    /** Byte sequences that never belong in an image we accept (polyglots / disguised scripts). */
    private const SUSPICIOUS = ['<?php', '<script', '<svg', '<html', '<iframe', '<!doctype'];

    /**
     * @return array{mime: string, width: int, height: int, bytes: int, sha256: string, ext: string}
     *
     * @throws MediaRejected
     */
    public function inspect(string $bytes, ?string $filename = null): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new MediaRejected('empty_file');
        }
        if ($size > (int) config('sitex.media.max_upload_bytes', 10 * 1024 * 1024)) {
            throw new MediaRejected('file_too_large', '', ['max_bytes' => (int) config('sitex.media.max_upload_bytes')]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (! isset(self::MIME_EXT[$mime])) {
            throw new MediaRejected('unsupported_type', '', ['mime' => $mime]);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info['mime'] !== $mime) {
            throw new MediaRejected('invalid_image');
        }
        if ($filename !== null && $filename !== '') {
            $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === '' || ! in_array($ext, self::MIME_EXT[$mime], true)) {
                throw new MediaRejected('extension_mismatch', '', ['mime' => $mime, 'extension' => $ext]);
            }
            if (preg_match('/\.(php\d?|phtml|phar|js|html?|svg|exe|sh|bat|cmd)\./i', $filename) === 1) {
                throw new MediaRejected('suspicious_content');
            }
        }
        $lower = strtolower($bytes);
        foreach (self::SUSPICIOUS as $marker) {
            if (str_contains($lower, $marker)) {
                throw new MediaRejected('suspicious_content');
            }
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $maxSide = (int) config('sitex.media.max_dimension', 8000);
        if ($w < 16 || $h < 16 || $w > $maxSide || $h > $maxSide || $w * $h > (int) config('sitex.media.max_pixels', 40_000_000)) {
            throw new MediaRejected('dimensions_out_of_range', '', ['width' => $w, 'height' => $h, 'max' => $maxSide]);
        }

        return ['mime' => $mime, 'width' => $w, 'height' => $h, 'bytes' => $size, 'sha256' => hash('sha256', $bytes), 'ext' => self::MIME_EXT[$mime][0]];
    }

    /**
     * @throws MediaRejected
     */
    public function decode(string $bytes, string $mime): GdImage
    {
        $img = @imagecreatefromstring($bytes);
        if (! $img instanceof GdImage) {
            throw new MediaRejected('invalid_image');
        }
        if ($mime === 'image/jpeg') {
            $img = $this->orient($img, $bytes);
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return $img;
    }

    /**
     * @return array{bytes: string, mime: string, ext: string, width: int, height: int}
     */
    public function encode(GdImage $img, string $format, int $quality = 82): array
    {
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($this->flatten($img), null, $quality),
            'png' => imagepng($img, null, 9),
            'gif' => imagegif($img),
            'webp' => imagewebp($img, null, $quality),
            'avif' => imageavif($img, null, max(30, $quality - 22), 6),
            default => throw new MediaRejected('unsupported_type'),
        };
        $bytes = (string) ob_get_clean();
        $mime = 'image/'.$format;

        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => $format === 'jpeg' ? 'jpg' : $format, 'width' => imagesx($img), 'height' => imagesy($img)];
    }

    public static function formatFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    public function supportsAvif(): bool
    {
        return function_exists('imageavif') && (bool) (gd_info()['AVIF Support'] ?? false);
    }

    /**
     * Scale down to fit inside max × max (never upscales).
     */
    public function fit(GdImage $img, int $maxWidth, int $maxHeight): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $maxWidth / $w, $maxHeight / $h);
        if ($scale >= 1.0) {
            return $img;
        }

        return $this->resample($img, 0, 0, $w, $h, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    }

    /**
     * Centre crop to an aspect ratio "W:H", or an explicit box.
     *
     * @param  array{x: int, y: int, width: int, height: int}|null  $box
     *
     * @throws MediaRejected
     */
    public function crop(GdImage $img, ?string $ratio, ?array $box = null): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($box !== null) {
            $x = max(0, $box['x']);
            $y = max(0, $box['y']);
            $cw = min($box['width'], $w - $x);
            $ch = min($box['height'], $h - $y);
            if ($cw < 16 || $ch < 16) {
                throw new MediaRejected('invalid_crop');
            }

            return $this->resample($img, $x, $y, $cw, $ch, $cw, $ch);
        }
        if ($ratio === null || preg_match('/^(\d{1,2}):(\d{1,2})$/', $ratio, $m) !== 1 || (int) $m[1] === 0 || (int) $m[2] === 0) {
            throw new MediaRejected('invalid_crop');
        }
        $target = (int) $m[1] / (int) $m[2];
        if ($w / $h > $target) {
            $cw = (int) round($h * $target);
            $ch = $h;
        } else {
            $cw = $w;
            $ch = (int) round($w / $target);
        }

        return $this->resample($img, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), $cw, $ch, $cw, $ch);
    }

    /**
     * 64-bit difference hash (9×8 grayscale) as 16 hex chars.
     */
    public function dhash(GdImage $img): string
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->luma($small, $x, $y) > $this->luma($small, $x + 1, $y) ? '1' : '0';
            }
        }
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex((int) bindec($nibble));
        }

        return $hex;
    }

    public static function hamming(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < min(strlen($a), strlen($b)); $i++) {
            $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }

        return $d;
    }

    private function luma(GdImage $img, int $x, int $y): int
    {
        $c = imagecolorat($img, $x, $y);

        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    }

    private function resample(GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): GdImage
    {
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    private function flatten(GdImage $img): GdImage
    {
        $out = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagealphablending($out, true);
        imagecopy($out, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        return $out;
    }

    private function orient(GdImage $img, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        return $rotated instanceof GdImage ? $rotated : $img;
    }
}
