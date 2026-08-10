<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Dziennik zdarzeń (tabela audit_log).
 *
 * Zapis do dziennika NIGDY nie może wywrócić operacji, którą opisuje: nieudane
 * logowanie ma zostać odrzucone także wtedy, gdy baza akurat nie przyjmuje wpisu.
 * Dlatego błędy zapisu lądują w logu serwera, a nie w wyjątku lecącym do góry.
 */
final class Audit
{
    // Akcje związane z logowaniem. Stałe, bo literówka w nazwie akcji
    // po cichu rozspójnia dziennik i nikt tego nie zauważy.
    public const LOGIN_OK           = 'login.ok';
    public const LOGIN_FAIL         = 'login.fail';
    public const LOGIN_RATE_LIMITED = 'login.rate_limited';
    public const LOGIN_BACKEND_DOWN = 'login.backend_down';
    public const LOGOUT             = 'logout';
    public const CSRF_FAIL          = 'csrf.fail';

    /** @param array<string,mixed>|null $meta */
    public static function log(
        string $action,
        ?int $userId = null,
        ?string $entity = null,
        ?int $entityId = null,
        ?array $meta = null,
    ): void {
        try {
            Db::run(
                'INSERT INTO audit_log (user_id, action, entity, entity_id, meta_json, ip)
                 VALUES (:user_id, :action, :entity, :entity_id, :meta, :ip)',
                [
                    'user_id'   => $userId,
                    'action'    => $action,
                    'entity'    => $entity,
                    'entity_id' => $entityId,
                    'meta'      => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'ip'        => self::packedIp(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('audit_log: nie udało się zapisać wpisu "' . $action . '": ' . $e->getMessage());
        }
    }

    /**
     * Kolumna `ip` jest typu VARBINARY(16) — trzymamy postać binarną, żeby IPv6
     * mieścił się bez skracania i żeby dało się porównywać adresy wprost.
     */
    public static function packedIp(): ?string
    {
        $ip = self::clientIp();
        if ($ip === null) {
            return null;
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    /**
     * Adres klienta. Nagłówków X-Forwarded-For NIE ufamy domyślnie — klient może
     * je ustawić dowolnie, a przy limicie logowania oznaczałoby to obejście limitu
     * przez podmianę nagłówka. Ufamy im tylko, gdy .env jawnie to włącza
     * (aplikacja stoi wtedy za znanym proxy).
     */
    public static function clientIp(): ?string
    {
        if (Config::bool('TRUST_PROXY', false)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($remote === null || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $remote;
    }
}
