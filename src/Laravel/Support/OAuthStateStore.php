<?php

namespace SistemasEel\SSOClient\Laravel\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class OAuthStateStore
{
    private const SESSION_KEY = 'sso_oauth_states';

    private const LEGACY_SESSION_KEY = 'oauth_state';

    private const CACHE_PREFIX = 'sso_oauth_state:';

    private const COOKIE_PREFIX = 'sso_oauth_state_';

    public function issue(string $state): void
    {
        $states = $this->validPendingStates();

        unset($states[$state]);

        while (count($states) >= $this->maxPending()) {
            $oldestState = array_key_first($states);

            if (! is_string($oldestState)) {
                break;
            }

            unset($states[$oldestState]);
            $this->forgetIndependentState($oldestState);
        }

        $issuedAt = now()->timestamp;
        $states[$state] = $issuedAt;

        $this->store($states);

        $binding = Str::random(64);

        Cache::put(
            $this->cacheKey($state),
            [
                'binding_hash' => hash('sha256', $binding),
                'issued_at' => $issuedAt,
                'intended_url' => $this->intendedUrlForNewFlow(),
            ],
            now()->addSeconds($this->ttlSeconds()),
        );

        Cookie::queue(Cookie::make(
            $this->cookieName($state),
            $binding,
            (int) ceil($this->ttlSeconds() / 60),
            $this->cookiePath(),
            $this->cookieDomain(),
            config('session.secure'),
            true,
            false,
            $this->cookieSameSite(),
        ));
    }

    /**
     * @param mixed $state
     */
    public function consume($state): ?ConsumedOAuthState
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $consumed = $this->consumeIndependentState($state);

        if ($consumed instanceof ConsumedOAuthState) {
            $this->removeSessionState($state);

            return $consumed;
        }

        return $this->consumeSessionState($state);
    }

    private function consumeIndependentState(
        string $state
    ): ?ConsumedOAuthState {
        $cookieName = $this->cookieName($state);
        $binding = request()->cookie($cookieName);

        if (! is_string($binding) || $binding === '') {
            return null;
        }

        try {
            return Cache::lock(
                $this->lockKey($state),
                $this->routeLockSeconds(),
            )->block(
                $this->routeLockWaitSeconds(),
                function () use (
                    $state,
                    $binding,
                    $cookieName
                ): ?ConsumedOAuthState {
                    $record = Cache::get($this->cacheKey($state));

                    if (! $this->validRecord($record)) {
                        return null;
                    }

                    if (! hash_equals(
                        $record['binding_hash'],
                        hash('sha256', $binding),
                    )) {
                        return null;
                    }

                    Cache::forget($this->cacheKey($state));
                    $this->expireCookie($cookieName);

                    return new ConsumedOAuthState(
                        $this->validIntendedUrl(
                            $record['intended_url'] ?? null,
                        ),
                    );
                },
            );
        } catch (LockTimeoutException $exception) {
            return null;
        }
    }

    /**
     * @param mixed $record
     */
    private function validRecord($record): bool
    {
        if (
            ! is_array($record)
            || ! isset($record['binding_hash'], $record['issued_at'])
            || ! is_string($record['binding_hash'])
            || ! is_numeric($record['issued_at'])
        ) {
            return false;
        }

        $issuedAt = (int) $record['issued_at'];
        $now = now()->timestamp;

        return $issuedAt >= $now - $this->ttlSeconds()
            && $issuedAt <= $now;
    }

    private function consumeSessionState(
        string $state
    ): ?ConsumedOAuthState {
        $states = $this->validPendingStates();

        if (array_key_exists($state, $states)) {
            unset($states[$state]);
            $this->store($states);
            $this->forgetIndependentState($state);

            return new ConsumedOAuthState(
                $this->intendedUrlFromSession(),
            );
        }

        $legacyState = Session::get(self::LEGACY_SESSION_KEY);

        if (
            is_string($legacyState)
            && hash_equals($legacyState, $state)
        ) {
            Session::forget(self::LEGACY_SESSION_KEY);
            $this->store($states);
            $this->forgetIndependentState($state);

            return new ConsumedOAuthState(
                $this->intendedUrlFromSession(),
            );
        }

        $this->store($states);

        return null;
    }

    private function removeSessionState(string $state): void
    {
        $states = $this->validPendingStates();

        unset($states[$state]);

        $this->store($states);
    }

    /**
     * @return array<string, int>
     */
    private function validPendingStates(): array
    {
        $now = now()->timestamp;
        $minimumTimestamp = $now - $this->ttlSeconds();
        $validStates = [];

        foreach ((array) Session::get(self::SESSION_KEY, []) as $state => $issuedAt) {
            if (
                ! is_string($state)
                || $state === ''
                || ! is_numeric($issuedAt)
            ) {
                continue;
            }

            $issuedAt = (int) $issuedAt;

            if ($issuedAt < $minimumTimestamp || $issuedAt > $now) {
                continue;
            }

            $validStates[$state] = $issuedAt;
        }

        return $validStates;
    }

    /**
     * @param array<string, int> $states
     */
    private function store(array $states): void
    {
        if ($states === []) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, $states);
    }

    private function forgetIndependentState(string $state): void
    {
        Cache::forget($this->cacheKey($state));
        $this->expireCookie($this->cookieName($state));
    }

    private function expireCookie(string $cookieName): void
    {
        Cookie::queue(Cookie::forget(
            $cookieName,
            $this->cookiePath(),
            $this->cookieDomain(),
        ));
    }

    private function intendedUrlForNewFlow(): ?string
    {
        return $this->validIntendedUrl(
            request()->query('intended'),
        ) ?? $this->intendedUrlFromSession();
    }

    private function intendedUrlFromSession(): ?string
    {
        return $this->validIntendedUrl(
            Session::get('url.intended'),
        );
    }

    /**
     * @param mixed $url
     */
    private function validIntendedUrl($url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return $url;
        }

        $application = parse_url((string) config('app.url'));
        $intended = parse_url($url);

        if (
            ! is_array($application)
            || ! is_array($intended)
            || ! isset(
                $application['scheme'],
                $application['host'],
                $intended['scheme'],
                $intended['host'],
            )
        ) {
            return null;
        }

        if (
            strtolower($application['scheme']) !== strtolower($intended['scheme'])
            || strtolower($application['host']) !== strtolower($intended['host'])
            || $this->effectivePort($application) !== $this->effectivePort($intended)
        ) {
            return null;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function effectivePort(array $parts): ?int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            ? 443
            : 80;
    }

    private function cacheKey(string $state): string
    {
        return self::CACHE_PREFIX.hash('sha256', $state);
    }

    private function lockKey(string $state): string
    {
        return $this->cacheKey($state).':lock';
    }

    private function cookieName(string $state): string
    {
        return self::COOKIE_PREFIX.hash('sha256', $state);
    }

    private function cookiePath(): string
    {
        $path = config('session.path', '/');

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function cookieDomain(): ?string
    {
        $domain = config('session.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function cookieSameSite(): ?string
    {
        $sameSite = config('session.same_site', 'lax');

        return is_string($sameSite) && $sameSite !== ''
            ? $sameSite
            : null;
    }

    private function ttlSeconds(): int
    {
        return max(
            1,
            (int) config('sso-client.oauth_state.ttl_seconds', 600),
        );
    }

    private function maxPending(): int
    {
        return max(
            1,
            (int) config('sso-client.oauth_state.max_pending', 10),
        );
    }

    private function routeLockSeconds(): int
    {
        return max(
            1,
            (int) config(
                'sso-client.oauth_state.route_lock_seconds',
                30,
            ),
        );
    }

    private function routeLockWaitSeconds(): int
    {
        return max(
            1,
            (int) config(
                'sso-client.oauth_state.route_lock_wait_seconds',
                30,
            ),
        );
    }
}
