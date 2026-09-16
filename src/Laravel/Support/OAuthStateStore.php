<?php

namespace SistemasEel\SSOClient\Laravel\Support;

use Illuminate\Support\Facades\Session;

class OAuthStateStore
{
    private const SESSION_KEY = 'sso_oauth_states';

    private const LEGACY_SESSION_KEY = 'oauth_state';

    public function issue(string $state): void
    {
        $states = $this->validPendingStates();

        unset($states[$state]);

        while (count($states) >= $this->maxPending()) {
            array_shift($states);
        }

        $states[$state] = now()->timestamp;

        $this->store($states);
    }

    /**
     * @param mixed $state
     */
    public function consume($state): bool
    {
        if (! is_string($state) || $state === '') {
            return false;
        }

        $states = $this->validPendingStates();

        if (array_key_exists($state, $states)) {
            unset($states[$state]);
            $this->store($states);

            return true;
        }

        $legacyState = Session::get(self::LEGACY_SESSION_KEY);

        if (
            is_string($legacyState)
            && hash_equals($legacyState, $state)
        ) {
            Session::forget(self::LEGACY_SESSION_KEY);
            $this->store($states);

            return true;
        }

        $this->store($states);

        return false;
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
}
