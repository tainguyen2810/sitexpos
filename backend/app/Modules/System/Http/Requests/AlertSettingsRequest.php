<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class AlertSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['emails' => ['present', 'array', 'max:10'], 'emails.*' => ['email:rfc', 'max:190'], 'telegram_bot_token' => ['nullable', 'string', 'max:120', 'regex:/^[0-9]+:[A-Za-z0-9_\\-]+$/'], 'telegram_chat_id' => ['nullable', 'string', 'max:40', 'regex:/^-?[0-9]+$|^@[A-Za-z0-9_]{5,}$/'], 'clear_telegram' => ['boolean'], 'cooldown_minutes' => ['nullable', 'integer', 'between:5,1440']];
    }
}
