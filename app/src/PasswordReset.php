<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Reset hasła przez e-mail.
 *
 * PODZIAŁ RÓL, od którego zależy bezpieczeństwo tej funkcji:
 *
 *  - WARSTWA ŻĄDAŃ kolejkuje samą prośbę (`queueRequest`) — zawsze, także dla
 *    adresu, którego nie ma. Nie sprawdza istnienia konta, więc odpowiedź HTTP
 *    jest identyczna w obu przypadkach i formularz nie służy do sondowania
 *    listy kont (ta sama zasada co DUMMY_HASH w Auth).
 *
 *  - PROCES ROBOCZY (`issue`, wołane z app/bin/run_job.php) rozstrzyga, czy
 *    konto istnieje, generuje token i wysyła mail. Surowy token żyje tylko
 *    w treści maila; w bazie ląduje wyłącznie skrót sha256. Dzięki temu ani
 *    zrzut bazy, ani podgląd tabeli `jobs` nie wystarczają do przejęcia konta.
 *
 * Token: 32 bajty z CSPRNG (256 bitów), ważny 1 h, jednorazowy. Nowa prośba
 * unieważnia wcześniejsze nieużyte tokeny — ważny jest najwyżej jeden odnośnik.
 */
final class PasswordReset
{
    public const WAZNOSC_SEKUND = 3600;

    /** Typ zadania w kolejce — obsługa w app/bin/run_job.php. */
    public const JOB_TYPE = 'reset_mail';

    // ------------------------------------------------------------ warstwa żądań

    /**
     * Prośba o reset do kolejki. BEZ sprawdzania, czy konto istnieje —
     * to rozstrzyga proces roboczy, poza zasięgiem pomiaru czasu odpowiedzi.
     */
    public static function queueRequest(string $email): void
    {
        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => self::JOB_TYPE,
                'payload' => json_encode(['email' => mb_substr(trim($email), 0, 190)], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        // Bez identyfikatora konta — prośba mogła dotyczyć adresu spoza systemu,
        // a wpis audytu nie może tego zdradzać różnicą treści.
        Audit::log('password.reset_requested', null, 'user', null);
    }

    // ------------------------------------------------------------ proces roboczy

    /**
     * Wydanie tokenu dla istniejącego konta. WYŁĄCZNIE z procesu roboczego.
     *
     * Zwraca SUROWY token — jedyne miejsce, w którym istnieje poza mailem.
     * Wcześniejsze nieużyte tokeny konta przestają obowiązywać.
     */
    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        Db::run(
            'DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $userId]
        );
        Db::run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:uid, :hash, :wygasa, :now)',
            [
                'uid'    => $userId,
                'hash'   => hash('sha256', $token),
                'wygasa' => Stats::now('+' . self::WAZNOSC_SEKUND . ' seconds'),
                'now'    => Stats::now(),
            ]
        );

        return $token;
    }

    // ------------------------------------------------------------ użycie odnośnika

    /**
     * Wiersz resetu dla ważnego tokenu: nieużytego i nieprzeterminowanego.
     *
     * Nieprawidłowy, zużyty i wygasły token dają to samo `null` — rozróżnienie
     * niczego nie ułatwia właścicielowi, a napastnikowi mówi, który token
     * był kiedyś prawdziwy.
     *
     * @return array<string,mixed>|null
     */
    public static function findValid(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $wiersz = Db::one(
            'SELECT r.*, u.email, u.status
               FROM password_resets r
               JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = :hash',
            ['hash' => hash('sha256', $token)]
        );

        if ($wiersz === null
            || $wiersz['used_at'] !== null
            || (string) $wiersz['expires_at'] < Stats::now()
            || (string) ($wiersz['status'] ?? 'active') === 'disabled') {
            return null;
        }

        return $wiersz;
    }

    /**
     * Ustawienie nowego hasła z odnośnika. Token staje się zużyty,
     * wszystkie sesje i zapamiętane urządzenia tracą dostęp.
     *
     * @param array<string,mixed> $reset wiersz z findValid()
     */
    public static function complete(array $reset, string $newPassword): void
    {
        $userId = (int) $reset['user_id'];

        Db::run('UPDATE users SET pass_hash = :hash WHERE id = :id', [
            'hash' => Auth::hashPassword($newPassword),
            'id'   => $userId,
        ]);
        Db::run('UPDATE password_resets SET used_at = :now WHERE id = :id', [
            'now' => Stats::now(),
            'id'  => (int) $reset['id'],
        ]);

        // Reset robi się najczęściej dlatego, że hasło mogło wpaść w cudze ręce.
        // Stare sesje wygasają same (odcisk hasha w Auth::currentUser),
        // a tokeny trwałe kasujemy wprost.
        Remember::forgetAll($userId, 'reset hasła');

        // Hasło ustawił właściciel (dowiódł kontroli nad skrzynką) — flaga
        // wymuszonej zmiany po haśle od administratora przestaje obowiązywać.
        Users::clearPasswordChangeFlag($userId);

        Audit::log('password.reset_completed', $userId, 'user', $userId);
    }
}
