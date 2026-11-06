<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Policy;

/**
 * Result of the policy engine for one proposed tool call.
 */
final class Decision
{
    public const ALLOW = 'allow';

    public const REQUIRE_APPROVAL = 'require_approval';

    public const DENY = 'deny';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly string $decision, public readonly array $reasons) {}

    /**
     * @param  list<string>  $reasons
     */
    public static function deny(array $reasons): self
    {
        return new self(self::DENY, $reasons);
    }
}
