<?php
declare(strict_types=1);

/**
 * Powtórzony symbol nazwany w zapytaniu = awaria na MySQL, cisza na SQLite.
 *
 * `Db` ustawia `PDO::ATTR_EMULATE_PREPARES => false`, czyli zapytanie przygotowuje
 * serwer bazy, a nie sterownik. MySQL/MariaDB NIE POZWALA wtedy użyć tego samego
 * symbolu dwa razy i odrzuca zapytanie komunikatem:
 *
 *     SQLSTATE[HY093]: Invalid parameter number
 *
 * SQLite powtórzenie przyjmuje bez mrugnięcia. Ponieważ testy chodzą na SQLite
 * (APP_ENV=test), taki błąd przechodzi CAŁY zestaw na zielono i wychodzi dopiero
 * na produkcji — dokładnie to zdarzyło się ekranowi `/raporty` przy filtrze
 * po klubie (`club_home_id = :club OR club_away_id = :club`).
 *
 * Tego NIE DA SIĘ złapać przebiegiem testów. Da się przeglądem zapytań.
 *
 * Uruchomienie:  php app/tests/test_sql_parametry.php
 */

$root = dirname(__DIR__, 2);

$ok = 0;
$fail = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    if ($condition) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * Fragmenty SQL w pliku — z napisów, po zdjęciu komentarzy.
 *
 * Komentarze w tym projekcie CYTUJĄ zapytania, żeby wyjaśnić, czemu wyglądają
 * tak, a nie inaczej. Bez ich usunięcia test zapalałby się na własnym
 * uzasadnieniu.
 *
 * @return list<string>
 */
function fragmentySql(string $kod): array
{
    $out = [];
    foreach (token_get_all($kod) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $tresc = trim($token[1], "'\"");
        // Interesują nas napisy niosące symbole nazwane. Sklejane zapytania
        // rozpadają się na kawałki — każdy sprawdzamy osobno, a powtórzenie
        // wewnątrz jednego kawałka i tak jest błędem.
        if (str_contains($tresc, ':')) {
            $out[] = $tresc;
        }
    }
    return $out;
}

/**
 * Powtórzone symbole w jednym fragmencie.
 *
 * @return list<string>
 */
function powtorzone(string $sql): array
{
    // `::` to operator PHP (Klasa::metoda), nie symbol zapytania — pomijamy.
    $bez = str_replace('::', '  ', $sql);
    preg_match_all('/(?<![a-z0-9_:]):([a-z_][a-z0-9_]*)/i', $bez, $m);

    $licznik = array_count_values($m[1]);
    return array_keys(array_filter($licznik, static fn(int $n) => $n > 1));
}

/** @return list<string> */
function plikiZZapytaniami(string $root): array
{
    $lista = array_merge(
        glob($root . '/app/src/*.php') ?: [],
        glob($root . '/app/bin/*.php') ?: [],
        [$root . '/app/public/index.php']
    );
    sort($lista);
    return array_values(array_filter($lista, 'is_file'));
}

echo "== przegląd zapytań ==\n";

$pliki = plikiZZapytaniami($root);
check('są pliki do przejrzenia', count($pliki) > 5, count($pliki) . ' plików');

$naruszenia = [];
foreach ($pliki as $plik) {
    $nazwa = str_replace($root . '/', '', $plik);
    foreach (fragmentySql((string) file_get_contents($plik)) as $sql) {
        foreach (powtorzone($sql) as $symbol) {
            $naruszenia[] = sprintf(
                '%s — :%s w „%s"',
                $nazwa,
                $symbol,
                trim(preg_replace('/\s+/', ' ', mb_substr($sql, 0, 70)) ?? $sql)
            );
        }
    }
}

check(
    'żadne zapytanie nie powtarza symbolu nazwanego',
    $naruszenia === [],
    implode(' | ', $naruszenia)
);

echo "\n== wykrywanie faktycznie działa ==\n";

// Bez tego zielony wynik nie znaczyłby nic.
check('wykrywa powtórzenie',
    powtorzone('SELECT * FROM t WHERE a = :x OR b = :x') === ['x']);
check('wykrywa dwa różne powtórzenia',
    powtorzone('SELECT * FROM t WHERE a = :x OR b = :x OR c = :y OR d = :y') === ['x', 'y']);
check('NIE zgłasza symboli użytych raz',
    powtorzone('SELECT * FROM t WHERE a = :x AND b = :y') === []);
check('NIE myli operatora :: z symbolem',
    powtorzone('SELECT * FROM t WHERE a = :x AND b = Klasa::STALA AND c = Klasa::STALA') === []);
check('NIE myli symbolu z jego przedrostkiem',
    powtorzone('SELECT * FROM t WHERE a = :klub AND b = :klub_id') === []);

echo "\n== komentarze nie fałszują wyniku ==\n";

$zKomentarzem = <<<'KOD'
<?php
// Kiedyś stało tu: WHERE club_home_id = :club OR club_away_id = :club
$sql = 'SELECT * FROM matches WHERE club_home_id = :club_h OR club_away_id = :club_a';
KOD;

$znalezione = [];
foreach (fragmentySql($zKomentarzem) as $sql) {
    $znalezione = array_merge($znalezione, powtorzone($sql));
}
check('powtórzenie w KOMENTARZU nie jest zgłaszane', $znalezione === [],
    'komentarze w tym projekcie cytują zapytania, żeby wyjaśnić poprawki');

$zBledem = <<<'KOD'
<?php
$sql = 'SELECT * FROM matches WHERE club_home_id = :club OR club_away_id = :club';
KOD;

$znalezione = [];
foreach (fragmentySql($zBledem) as $sql) {
    $znalezione = array_merge($znalezione, powtorzone($sql));
}
check('powtórzenie w KODZIE jest zgłaszane', $znalezione === ['club']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
