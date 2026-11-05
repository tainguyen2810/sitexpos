<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;

/**
 * Per-site shared secret for HMAC-SHA256 request signing (both directions).
 * The secret is encrypted at rest and never returned by any API resource.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $key_id
 * @property string $secret base64url (decrypted through the cast)
 * @property CredentialStatus $status
 * @property Carbon|null $grace_until
 * @property Carbon|null $last_used_at
 * @property Carbon|null $rotated_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site $site
 */
class SiteCredential extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'grace_until' => null, 'last_used_at' => null, 'rotated_at' => null, 'revoked_at' => null];

    /** @var list<string> */
    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'grace_until' => 'datetime',
            'last_used_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function getSecretAttribute(): string
    {
        return (string) decrypt((string) $this->attributes['secret_encrypted'], false);
    }

    public function setSecretAttribute(string $secret): void
    {
        $this->attributes['secret_encrypted'] = encrypt($secret, false);
    }

    /**
     * Active keys verify; rotated keys verify only during the overlap window.
     */
    public function acceptsRequests(): bool
    {
        return match ($this->status) {
            CredentialStatus::Active => true,
            CredentialStatus::Rotated => $this->grace_until !== null && $this->grace_until->isFuture(),
            CredentialStatus::Revoked => false,
        };
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
