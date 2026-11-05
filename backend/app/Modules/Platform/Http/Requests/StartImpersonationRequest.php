<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-only request: workspace_id is legitimately chosen by platform staff
 * here, so it does not extend ApiRequest's tenant-field prohibition.
 */
final class StartImpersonationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'string', 'ulid'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
