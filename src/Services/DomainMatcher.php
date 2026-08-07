<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use Throwable;

/**
 * Shared host normalisation + client_sites lookup for read-only integrations
 * that arrive with a URL or hostname rather than a CRM id (WPMGR, Uptime Kuma).
 *
 * domains.domain is stored as typed — no lowercasing, no www-stripping, and no
 * uniqueness constraint (see DomainController::sanitise()) — so every match has
 * to normalise the incoming host and compare LOWER()-to-LOWER().
 */
final class DomainMatcher
{
    /** Bare, lowercased, www-stripped host from a URL (or a bare hostname). */
    public static function hostFromUrl(string $url): string
    {
        $url  = trim($url);
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return preg_replace('/^www\./i', '', strtolower(trim($host))) ?? '';
    }

    /** The client_sites row whose domain matches $host, or null. */
    public static function findClientSiteByHost(PDO $db, string $host): ?int
    {
        if ($host === '') return null;

        try {
            $stmt = $db->prepare(
                "SELECT cs.id FROM client_sites cs
                 JOIN domains d ON d.id = cs.domain_id
                 WHERE LOWER(d.domain) = LOWER(?)
                 LIMIT 1"
            );
            $stmt->execute([$host]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
