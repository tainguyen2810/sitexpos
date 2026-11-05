<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for all API input. Tenant and privilege fields can never be
 * supplied by the client: the tenant comes from the URL + membership and
 * privileges from the server-side role.
 */
abstract class ApiRequest extends FormRequest
{
    /** Fields that are always rejected when present in client input. */
    public const FORBIDDEN_FIELDS = ['workspace_id', 'tenant_id', 'platform_role', 'owner_id', 'user_id'];

    /**
     * @return array<string, mixed>
     */
    abstract protected function fieldRules(): array;

    /**
     * @return array<string, mixed>
     */
    final public function rules(): array
    {
        $rules = $this->fieldRules();

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (! array_key_exists($field, $rules)) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    public function authorize(): bool
    {
        // Authorization is performed explicitly in controllers via policies.
        return true;
    }
}
