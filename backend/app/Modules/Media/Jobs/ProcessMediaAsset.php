<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Jobs;

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Support\MediaPipeline;
use SiteXPost\Modules\Media\Support\MediaRejected;
use SiteXPost\Modules\Media\Support\MediaStorage;

/**
 * Re-encode + variants + duplicate detection for an uploaded file. The raw
 * upload is deleted afterwards whatever the outcome.
 */
final class ProcessMediaAsset extends MediaJob
{
    public function __construct(public string $assetId, public string $incomingPath, ?string $jobRunId = null)
    {
        parent::__construct($jobRunId);
    }

    protected function run(?JobRun $run): void
    {
        $storage = app(MediaStorage::class);
        $asset = MediaAsset::query()->find($this->assetId);
        $bytes = $storage->get($this->incomingPath);
        try {
            if ($asset === null || $bytes === null) {
                self::failRun($run, 'not_found', __('api.not_found'));

                return;
            }
            app(MediaPipeline::class)->ingest($asset, $bytes, $asset->original_filename);
        } catch (MediaRejected $e) {
            $asset->forceFill(['status' => 'rejected', 'error_code' => $e->errorCode])->save();
            self::failRun($run, $e->errorCode, $e->getMessage());
        } finally {
            $storage->disk()->delete($this->incomingPath);
        }
    }
}
