<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Limit prób logowania w Redis (CLAUDE.md §5).
 *
 * Liczymy w dwóch wymiarach naraz:
 *  - po adresie IP        — jeden atakujący próbujący wielu haseł,
 *  - po loginie           — rozproszony atak na jedno konto z wielu adresów.
 * Sam licznik po IP przepuściłby drugi przypadek, sam po loginie — pierwszy.
 *
 * DECYZJA: przy niedostępnym Redisie logowanie jest BLOKOWANE (fail closed).
 * Przepuszczenie prób „bo licznik nie działa" znosi całą ochronę dokładnie
 * wtedy, gdy nikt nie patrzy. Awaria jest odnotowana w audit_log, a serwer stoi
 * po stronie wykonawcy (D6), więc jest kto ją naprawić.
 */
final class RateLimit
{
    public const IP_LIMIT     = 10;   // prób na okno
    public const LOGIN_LIMIT  = 5;
    public const WINDOW_SEC   = 900;  // 15 minut

    public function __construct(private ?RedisClient $redis = null) {}

    private function redis(): RedisClient
    {
        return $this->redis ??= RedisClient::fromConfig();
    }

    /**
     * Czy próba jest dozwolona. Zwraca liczbę sekund do odblokowania (0 = wolno).
     *
     * @throws \RuntimeException gdy Redis jest niedostępny — obsługiwane przez Auth
     */
    public function check(string $email): int
    {
        $ip = Audit::clientIp() ?? 'brak-ip';
        $keys = [
            'login:ip:' . $ip                  => self::IP_LIMIT,
            'login:acc:' . $this->hash($email) => self::LOGIN_LIMIT,
        ];

        $blockedFor = 0;
        foreach ($keys as $key => $limit) {
            $value = $this->redis()->get($key);
            if ($value !== null && (int) $value >= $limit) {
                $ttl = $this->redis()->ttl($key);
                $blockedFor = max($blockedFor, $ttl > 0 ? $ttl : self::WINDOW_SEC);
            }
        }
        return $blockedFor;
    }

    /** Nieudana próba podbija oba liczniki. */
    public function registerFailure(string $email): void
    {
        $ip = Audit::clientIp() ?? 'brak-ip';
        foreach (['login:ip:' . $ip, 'login:acc:' . $this->hash($email)] as $key) {
            $count = $this->redis()->incr($key);
            if ($count === 1) {
                // Okno ustawiamy przy pierwszym trafieniu — kolejne próby go NIE
                // przedłużają, więc blokada nie rośnie w nieskończoność.
                $this->redis()->expire($key, self::WINDOW_SEC);
            }
        }
    }

    /** Udane logowanie zeruje liczniki, żeby literówka nie blokowała pracy. */
    public function clear(string $email): void
    {
        $ip = Audit::clientIp() ?? 'brak-ip';
        $this->redis()->del('login:ip:' . $ip);
        $this->redis()->del('login:acc:' . $this->hash($email));
    }

    /**
     * Adresu e-mail nie wkładamy do klucza w postaci jawnej — Redis bywa wspólny
     * dla wielu usług na hostingu, a lista loginów to informacja sama w sobie.
     */
    private function hash(string $email): string
    {
        return substr(hash('sha256', strtolower(trim($email))), 0, 32);
    }
}
