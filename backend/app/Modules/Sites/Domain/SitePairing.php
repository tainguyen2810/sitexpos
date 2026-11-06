<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * One-time pairing code. Only an HMAC of the code is stored.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $invalidated_at
 * @property int $attempts
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site $site
 */
class SitePairing extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** Unambiguous alphabet (no 0/O, 1/I/L). */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['used_at' => null, 'invalidated_at' => null, 'attempts' => 0, 'created_by' => null];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public static function generateCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4);
    }

    public static function normalizeCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', self::normalizeCode($code), (string) config('app.key'));
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('sitex.connector.pairing_max_attempts', 5);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
