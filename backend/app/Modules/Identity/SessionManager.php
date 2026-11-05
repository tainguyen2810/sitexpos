<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Identity\Domain\User;

/**
 * Lists and revokes a user's sessions (requires SESSION_DRIVER=database).
 * Raw session ids are secrets and are never returned: clients get a
 * SHA-256 derived handle instead.
 */
final class SessionManager
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<array{id:string,ip_address:string|null,user_agent:string|null,last_active_at:string,is_current:bool}>
     */
    public function list(User $user): array
    {
        if (! $this->supported()) {
            return [];
        }

        $current = $this->currentId();

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => self::handle((string) $row->id),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent !== null ? mb_substr((string) $row->user_agent, 0, 255) : null,
                'last_active_at' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601ZuluString(),
                'is_current' => $row->id === $current,
            ])
            ->values()
            ->all();
    }

    public function revoke(User $user, string $handle): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $ids = DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->pluck('id');

        foreach ($ids as $id) {
            if (hash_equals(self::handle((string) $id), $handle)) {
                return DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', $id)
                    ->delete() > 0;
            }
        }

        return false;
    }

    public function revokeOthers(User $user): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($this->currentId() !== null, fn ($q) => $q->where('id', '!=', $this->currentId()))
            ->delete();
    }

    public function revokeAll(User $user): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }

    public static function handle(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    private function currentId(): ?string
    {
        $request = request();

        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
