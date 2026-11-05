<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;
use SiteXPost\Modules\Sites\Enums\SiteStatus;

/**
 * A WordPress website connected through the SiteX Post plugin.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $url
 * @property string $url_hash
 * @property SiteStatus $status
 * @property string $content_language
 * @property string|null $wp_version
 * @property string|null $php_version
 * @property string|null $plugin_version
 * @property int|null $publish_author_wp_id
 * @property string|null $publish_author_name
 * @property string $approval_mode
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $capabilities
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_health_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Site extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'content_language', 'approval_mode', 'settings'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_pairing',
        'content_language' => 'vi',
        'approval_mode' => 'manual',
        'settings' => null,
        'capabilities' => null,
        'wp_version' => null,
        'php_version' => null,
        'plugin_version' => null,
        'publish_author_wp_id' => null,
        'publish_author_name' => null,
        'last_error_code' => null,
        'last_error_message' => null,
        'last_seen_at' => null,
        'last_health_at' => null,
        'last_synced_at' => null,
        'connected_at' => null,
        'revoked_at' => null,
        'revoked_by' => null,
        'created_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'publish_author_wp_id' => 'integer',
            'settings' => 'array',
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
            'last_health_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Canonical form used for duplicate detection and pairing URL matching:
     * lowercase host, no default port, no trailing slash, scheme ignored.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $portPart = $port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port;

        return $scheme.'://'.$host.$portPart.$path;
    }

    public static function hashUrl(string $url): string
    {
        $normalized = self::normalizeUrl($url);

        return hash('sha256', (string) preg_replace('#^https?://#', '', $normalized));
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    /**
     * @return HasMany<SiteCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(SiteCredential::class);
    }

    public function activeCredential(): ?SiteCredential
    {
        return $this->credentials()->where('status', CredentialStatus::Active)->latest('created_at')->first();
    }

    /**
     * @return HasMany<SitePairing, $this>
     */
    public function pairings(): HasMany
    {
        return $this->hasMany(SitePairing::class);
    }

    /**
     * @return HasMany<SiteHealthCheck, $this>
     */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(SiteHealthCheck::class);
    }

    /**
     * @return HasMany<SiteTaxonomy, $this>
     */
    public function taxonomies(): HasMany
    {
        return $this->hasMany(SiteTaxonomy::class);
    }

    /**
     * @return HasMany<SiteContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(SiteContentItem::class);
    }

    /**
     * @return HasMany<SitePublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(SitePublication::class);
    }

    /**
     * @return HasMany<SiteMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(SiteMedia::class);
    }

    /**
     * @return HasMany<SiteActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(SiteActivityLog::class);
    }
}
