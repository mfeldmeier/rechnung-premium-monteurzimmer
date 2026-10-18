<?php

declare(strict_types=1);

namespace Pmz\Http;

use RuntimeException;

/**
 * Erlaubt es tief liegendem Code (z. B. Authenticator::requireLogin) eine
 * Weiterleitung auszuloesen, ohne selbst Header zu senden. Der Router faengt
 * die Exception und macht daraus eine normale Response - so bleibt alles
 * ohne exit() testbar.
 */
final class RedirectException extends RuntimeException
{
    public function __construct(public readonly string $target)
    {
        parent::__construct('Weiterleitung nach ' . $target);
    }
}
