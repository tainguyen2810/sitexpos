<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use SiteXPost\Kernel\Http\ApiException;

/**
 * A file or request the Media Engine refuses. `errorCode` is stable and
 * the message is safe to show to customers. Rendered with the standard
 * error envelope (422; 429 for quota limits; 403 when AI images are off).
 */
final class MediaRejected extends ApiException
{
    private const QUOTA = ['storage_quota_exceeded', 'daily_upload_limit', 'daily_ai_image_limit'];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $errorCode, string $message = '', array $details = [])
    {
        $status = in_array($errorCode, self::QUOTA, true) ? 429 : ($errorCode === 'ai_images_disabled' ? 403 : 422);
        parent::__construct($status, $errorCode, $message !== '' ? $message : (string) __('media.errors.'.$errorCode), $details);
    }
}
