<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Powiadomienia: w aplikacji zawsze, mailem opcjonalnie.
 *
 * ZASADA: powstanie powiadomienia NIGDY nie może przewrócić czynności, o której
 * powiadamia. Raport wygenerowany poprawnie zostaje poprawny także wtedy, gdy
 * padnie baza powiadomień albo serwer poczty. Dlatego `create()` łapie wszystko
 * i zwraca `null`, zamiast rzucać wyjątkiem — a wołający tego nie sprawdza.
 *
 * Kanał mailowy jest tu wyłącznie STANEM WYSYŁKI na tym samym wierszu
 * (patrz `app/migrations/006_notifications.sql`). Sama wysyłka nie dzieje się
 * tutaj: `create()` co najwyżej wstawia zadanie do kolejki.
 */
final class Notifications
{
    /** Zwłoka maila „przetwarzanie w toku". Poniżej tego czasu mail byłby spóźniony. */
    public const OPOZNIENIE_PENDING = 120;

    public const TYP_PENDING = 'import.pending';
    public const TYP_READY   = 'report.ready';
    public const TYP_FAILED  = 'report.failed';

    /** Kolumna preferencji dla danego typu. Typ spoza mapy nie idzie mailem nigdy. */
    private const PREFERENCJE = [
        self::TYP_PENDING => 'notify_mail_pending',
        self::TYP_READY   => 'notify_mail_ready',
        self::TYP_FAILED  => 'notify_mail_failed',
    ];

    /**
     * Nowe powiadomienie.
     *
     * @param array{
     *     type:string, title:string, body?:?string, entity?:?string,
     *     entity_id?:?int, url?:?string, mail?:bool, delay?:int
     * } $dane
     * @return int|null identyfikator albo null, gdy cokolwiek poszło nie tak
     */
    public static function create(int $userId, array $dane): ?int
    {
        try {
            self::assertUsableTarget($dane);
            return self::insert($userId, $dane);
        } catch (\Throwable $e) {
            // Log, nie wyjątek. Utrata powiadomienia jest przykra; utrata raportu
            // przez to, że nie dało się o nim powiadomić, byłaby absurdem.
            error_log('notifications: nie udało się zapisać powiadomienia — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Powiadomienie MUSI prowadzic tam, gdzie cos jest.
     *
     * USTERKA Z PRODUKCJI: pierwszy mail z serwera prowadzil do `/raport/0`.
     * Identyfikator raportu byl odczytywany za pozno i przychodzil tu jako zero,
     * a powiadomienie powstawalo bez szemrania — z odnosnikiem donikad.
     *
     * Odbiorca maila nie ma jak sprawdzic, czy adres jest prawdziwy; klika.
     * Dlatego zamiast wysylac powiadomienie z zepsutym odnosnikiem, odmawiamy
     * jego utworzenia i zostawiamy w logu jednoznaczny slad.
     *
     * @param array<string,mixed> $dane
     * @throws \RuntimeException gdy cel powiadomienia nie istnieje
     */
    private static function assertUsableTarget(array $dane): void
    {
        $encja = (string) ($dane['entity'] ?? '');
        $adres = (string) ($dane['url'] ?? '');

        // Zero i pustka to NIE sa prawidlowe identyfikatory. `entity_id`
        // sprawdzamy dla wszystkich encji wskazujacych konkretny wiersz.
        if (in_array($encja, ['report', 'job', 'import', 'match'], true)) {
            $id = $dane['entity_id'] ?? null;
            if ($id === null || (int) $id <= 0) {
                throw new \RuntimeException(
                    "Powiadomienie typu {$encja} bez prawidlowego identyfikatora (otrzymano: "
                    . var_export($id, true) . ')'
                );
            }
        }

        // Odnosnik z zerem w miejscu identyfikatora — dokladnie objaw z produkcji.
        if ($adres !== '' && preg_match('#/(0+)(/|$)#', $adres) === 1) {
            throw new \RuntimeException("Powiadomienie z odnosnikiem do zerowego identyfikatora: {$adres}");
        }
    }

    /**
     * @param array{
     *     type:string, title:string, body?:?string, entity?:?string,
     *     entity_id?:?int, url?:?string, mail?:bool, delay?:int
     * } $dane
     */
    private static function insert(int $userId, array $dane): int
    {
        $typ    = (string) $dane['type'];
        $opoznienie = (int) ($dane['delay'] ?? 0);
        $chceMail   = (bool) ($dane['mail'] ?? true);

        $adres = self::adresJesliChce($userId, $typ, $chceMail);

        $sendAfter = $opoznienie > 0 ? Stats::now('+' . $opoznienie . ' seconds') : null;

        Db::run(
            'INSERT INTO notifications
                (user_id, type, title, body, entity, entity_id, url,
                 created_at, mail_status, mail_to, send_after)
             VALUES (:uid, :typ, :tytul, :tresc, :ent, :entid, :url,
                     :teraz, :mstatus, :mto, :sendafter)',
            [
                'uid'       => $userId,
                'typ'       => $typ,
                'tytul'     => mb_substr((string) $dane['title'], 0, 200),
                'tresc'     => isset($dane['body']) ? (string) $dane['body'] : null,
                'ent'       => $dane['entity'] ?? null,
                'entid'     => $dane['entity_id'] ?? null,
                'url'       => $dane['url'] ?? null,
                'teraz'     => Stats::now(),
                'mstatus'   => $adres === null ? 'none' : 'pending',
                'mto'       => $adres,
                'sendafter' => $sendAfter,
            ]
        );

        $id = (int) Db::pdo()->lastInsertId();

        // Zadanie wysyłki powstaje TYLKO gdy jest co wysyłać. Kolejka zapchana
        // zadaniami, które natychmiast stwierdzają „nie ma adresu", utrudniałaby
        // czytanie podglądu zadań.
        if ($adres !== null) {
            self::queueMail($id, $sendAfter);
        }

        return $id;
    }

    /**
     * Adres odbiorcy albo null, gdy mail nie ma iść.
     *
     * Trzy niezależne powody, dla których maila nie będzie, i wszystkie trzy są
     * normalnym stanem: brak SMTP w konfiguracji, wyłączony przełącznik u
     * użytkownika, brak adresu na koncie.
     */
    private static function adresJesliChce(int $userId, string $typ, bool $chceMail): ?string
    {
        if (!$chceMail || !isset(self::PREFERENCJE[$typ])) {
            return null;
        }
        if (!Mailer::isConfigured()) {
            return null;
        }

        $user = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            return null;
        }

        $kolumna = self::PREFERENCJE[$typ];
        // Kolumna może nie istnieć na bazie sprzed migracji 006 — wtedy zachowujemy
        // się jak przy zgodzie, bo domyślną wartością w migracji jest 1.
        if (array_key_exists($kolumna, $user) && (int) $user[$kolumna] !== 1) {
            return null;
        }

        $adres = trim((string) ($user['email'] ?? ''));
        return filter_var($adres, FILTER_VALIDATE_EMAIL) !== false ? $adres : null;
    }

    private static function queueMail(int $notificationId, ?string $availableAt): void
    {
        Db::run(
            "INSERT INTO jobs (type, payload_json, status, created_at, available_at)
             VALUES ('send_mail', :payload, 'queued', :teraz, :dostepne)",
            [
                'payload'  => json_encode(['notification_id' => $notificationId], JSON_UNESCAPED_UNICODE),
                'teraz'    => Stats::now(),
                'dostepne' => $availableAt,
            ]
        );
    }

    // ---------------------------------------------------------------- odczyt

    public static function unreadCount(int $userId): int
    {
        try {
            $wiersz = Db::one(
                'SELECT COUNT(*) AS ile FROM notifications WHERE user_id = :uid AND read_at IS NULL',
                ['uid' => $userId]
            );
            return (int) ($wiersz['ile'] ?? 0);
        } catch (\Throwable $e) {
            // Licznik w nagłówku nie może wywrócić każdej strony w aplikacji.
            error_log('notifications: licznik nieodczytanych niedostępny — ' . $e->getMessage());
            return 0;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50): array
    {
        // LIMIT jako parametr zapytania, nie sklejany z liczbą.
        return Db::all(
            'SELECT * FROM notifications WHERE user_id = :uid
              ORDER BY created_at DESC, id DESC LIMIT :lim',
            ['uid' => $userId, 'lim' => max(1, min(200, $limit))]
        );
    }

    /**
     * Nieodczytane powiadomienia do pokazania jako chmurki.
     *
     * Nieodczytane, a nie „nowe od czasu X": stan „odczytane" jest jedynym
     * trwałym śladem tego, co użytkownik już widział, i trzyma się go serwer.
     * Znacznik czasu po stronie klienta rozjeżdżałby się przy dwóch otwartych
     * kartach i przy przestawionym zegarze.
     *
     * @return list<array<string,mixed>>
     */
    public static function unreadForToasts(int $userId, int $limit = 5): array
    {
        return Db::all(
            'SELECT id, type, title, body, url, created_at
               FROM notifications
              WHERE user_id = :uid AND read_at IS NULL
              ORDER BY id DESC LIMIT :lim',
            ['uid' => $userId, 'lim' => max(1, min(20, $limit))]
        );
    }

    /**
     * Odmiana chmurki. Trzy, nie tyle ile typów powiadomień — chmurka ma
     * przekazać „udało się / trwa / nie wyszło" jednym spojrzeniem.
     */
    public static function kind(string $type): string
    {
        return match ($type) {
            self::TYP_READY   => 'ready',
            self::TYP_FAILED  => 'failed',
            default           => 'pending',
        };
    }

    /**
     * Czy użytkownik ma coś w robocie.
     *
     * Skrypt skraca dzięki temu odstęp odpytywania: gdy silnik pracuje, warto
     * pytać częściej, a gdy nie dzieje się nic — nie ma po co.
     */
    public static function hasActiveWork(int $userId): bool
    {
        $wiersz = Db::one(
            "SELECT COUNT(*) AS ile
               FROM matches
              WHERE owner_id = :uid AND status IN ('queued', 'running')",
            ['uid' => $userId]
        );
        return (int) ($wiersz['ile'] ?? 0) > 0;
    }

    /**
     * Oznaczenie JEDNEGO powiadomienia jako odczytane.
     *
     * Warunek `user_id` jest w zapytaniu, nie w PHP: bez niego znajomość
     * samego identyfikatora pozwalałaby oznaczać cudze powiadomienia.
     * Identyfikatory są kolejnymi liczbami, więc zgadnięcie cudzego jest darmowe.
     */
    public static function markRead(int $id, int $userId): bool
    {
        return Db::run(
            'UPDATE notifications SET read_at = :teraz
              WHERE id = :id AND user_id = :uid AND read_at IS NULL',
            ['teraz' => Stats::now(), 'id' => $id, 'uid' => $userId]
        )->rowCount() === 1;
    }

    /** Zerowanie licznika przy wejściu na listę. @return int ile oznaczono */
    public static function markAllRead(int $userId): int
    {
        return Db::run(
            'UPDATE notifications SET read_at = :teraz WHERE user_id = :uid AND read_at IS NULL',
            ['teraz' => Stats::now(), 'uid' => $userId]
        )->rowCount();
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM notifications WHERE id = :id', ['id' => $id]);
    }

    // ---------------------------------------------------------------- wysyłka

    /**
     * Czy powód wysłania nadal obowiązuje.
     *
     * Sedno wymagania „mail o przetwarzaniu tylko wtedy, gdy zadanie czeka dłużej
     * niż dwie minuty": zwłoka sama z siebie nie wystarcza. Po dwóch minutach
     * raport może już być gotowy — wtedy mail „przetwarzanie w toku" byłby
     * nieprawdą i trafiłby do skrzynki PO mailu „raport gotowy".
     *
     * Sprawdzamy więc stan w chwili wysyłki, nie w chwili zaplanowania.
     */
    public static function stillRelevant(array $notification): bool
    {
        if ((string) $notification['type'] !== self::TYP_PENDING) {
            return true;
        }

        $matchId = (int) ($notification['entity_id'] ?? 0);
        if ($matchId <= 0) {
            return true;
        }

        $match = Db::one('SELECT status FROM matches WHERE id = :id', ['id' => $matchId]);
        if ($match === null) {
            return false; // Mecz zniknął — nie ma o czym powiadamiać.
        }

        return in_array((string) $match['status'], ['queued', 'running'], true);
    }

    public static function markSent(int $id): void
    {
        Db::run(
            "UPDATE notifications
                SET mail_status = 'sent', mail_sent_at = :teraz,
                    mail_attempts = mail_attempts + 1, mail_error = NULL
              WHERE id = :id",
            ['teraz' => Stats::now(), 'id' => $id]
        );
    }

    public static function markSkipped(int $id, string $powod): void
    {
        Db::run(
            "UPDATE notifications SET mail_status = 'skipped', mail_error = :powod WHERE id = :id",
            ['powod' => mb_substr($powod, 0, 500), 'id' => $id]
        );
    }

    /**
     * Ile razy próbujemy wysłać jeden mail, zanim uznamy sprawę za przegraną.
     *
     * Awarie SMTP są w przewadze CHWILOWE: przeciążony serwer, zerwana sieć,
     * limit wysyłek na minutę. Jedna próba zamieniałaby każde takie potknięcie
     * w trwale utracone powiadomienie.
     */
    public const MAX_PROB_MAILA = 5;

    /**
     * Odstępy między próbami, w sekundach. Rosnące, bo powtarzanie co minutę
     * pod serwer, który właśnie nas ogranicza, pogarsza sprawę zamiast pomagać.
     */
    private const ODSTEPY = [60, 300, 900, 3600];

    /**
     * Ile czekać przed kolejną próbą — albo `null`, gdy próby się skończyły.
     *
     * Reguła siedzi TUTAJ, a nie w procesie roboczym, z jednego powodu:
     * `run_job.php` jest skryptem, który przy dołączeniu od razu przetwarza
     * kolejkę, więc niczego w nim nie da się sprawdzić testem inaczej niż przez
     * uruchomienie całego workera. Polityka ponawiania jest regułą produktu
     * i ma być sprawdzalna wprost.
     *
     * @param int $probyDotad ile prób już się odbyło (0 przy pierwszej awarii)
     */
    public static function retryDelay(int $probyDotad): ?int
    {
        if ($probyDotad + 1 >= self::MAX_PROB_MAILA) {
            return null;
        }
        return self::ODSTEPY[min($probyDotad, count(self::ODSTEPY) - 1)];
    }

    /**
     * Nieudana próba, ale NIE koniec — powiadomienie zostaje `pending`.
     *
     * Odróżnienie od `markFailed()` jest tu istotą działania: dopóki stan to
     * `pending`, kolejna próba w ogóle dojdzie do skutku. Gdyby każda porażka
     * ustawiała `failed`, ponowienie zadania kończyłoby się natychmiastowym
     * „już obsłużone" i mail nigdy by nie wyszedł — a wyglądałoby to na
     * działający mechanizm ponawiania.
     */
    public static function markRetry(int $id, string $powod): void
    {
        Db::run(
            "UPDATE notifications
                SET mail_attempts = mail_attempts + 1, mail_error = :powod
              WHERE id = :id",
            ['powod' => mb_substr($powod, 0, 500), 'id' => $id]
        );
    }

    public static function markFailed(int $id, string $powod): void
    {
        Db::run(
            "UPDATE notifications
                SET mail_status = 'failed', mail_attempts = mail_attempts + 1,
                    mail_error = :powod
              WHERE id = :id",
            ['powod' => mb_substr($powod, 0, 500), 'id' => $id]
        );
    }

    // ---------------------------------------------------------------- ustawienia

    /** @return array<string,bool> */
    public static function preferences(array $user): array
    {
        $out = [];
        foreach (self::PREFERENCJE as $typ => $kolumna) {
            $out[$typ] = !array_key_exists($kolumna, $user) || (int) $user[$kolumna] === 1;
        }
        return $out;
    }

    /** @param array<string,bool> $wybor */
    public static function savePreferences(int $userId, array $wybor): void
    {
        $ustaw = [];
        $params = ['id' => $userId];
        foreach (self::PREFERENCJE as $typ => $kolumna) {
            $ustaw[] = "{$kolumna} = :{$kolumna}";
            $params[$kolumna] = !empty($wybor[$typ]) ? 1 : 0;
        }
        Db::run('UPDATE users SET ' . implode(', ', $ustaw) . ' WHERE id = :id', $params);
    }
}
