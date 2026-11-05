<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

/**
 * Admin-only: assigning platform_role is the purpose of this endpoint.
 */
final class StaffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'email'],
            'platform_role' => ['present', 'nullable', Rule::enum(PlatformRole::class)],
        ];
    }
}
