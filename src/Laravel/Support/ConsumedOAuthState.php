<?php

namespace SistemasEel\SSOClient\Laravel\Support;

final class ConsumedOAuthState
{
    /** @var string|null */
    private $intendedUrl;

    public function __construct(?string $intendedUrl)
    {
        $this->intendedUrl = $intendedUrl;
    }

    public function intendedUrl(): ?string
    {
        return $this->intendedUrl;
    }
}