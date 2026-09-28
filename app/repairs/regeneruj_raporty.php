<?php
declare(strict_types=1);

/**
 * Regeneracja raportów istniejących meczów — KOLEJKOWANIE, nie liczenie.
 *
 *   php app/repairs/regeneruj_raporty.php --club 3 [--dry-run]
 *   php app/repairs/regeneruj_raporty.php --all    [--dry-run]
 *   php app/repairs/regeneruj_raporty.php --match 26 [--match 27] [--dry-run]
 *   php app/repairs/regeneruj_raporty.php --nieaktualne [--club 2] [--dry-run]
 *   … [--z-rodzenstwem]   także starsze raporty meczu (domyślnie tylko najnowszy)
 *
 * GOLDEN LAYOUT W4 — trzy zmiany po odbiorze na produkcji:
 *
 *   1. JEDEN RAPORT NA MECZ, najnowszy — jak `Rebuilds::queueClub()`. Starszy
 *      raport meczu jest śladem wcześniejszych liczb (CLAUDE.md §7) i nie ma
 *      być po cichu podmieniany. Dodatkowo przeliczenie podnosi `generated_at`,
 *      więc przeliczony STARSZY raport wskakiwał na miejsce najnowszego na liście
 *      „wiersz = mecz". `--z-rodzenstwem` przywraca stare zachowanie świadomie.
 *   2. JEDNA PARTIA na przebieg: jedno zbiorcze powiadomienie „Przeliczono N"
 *      po ostatnim zadaniu, bez maili (dotąd chmurka i mail na każdy raport).
 *   3. TEMPLAT WIDAĆ PRZED KOLEJKOWANIEM: przy każdej pozycji wersja templatu,
 *      której użyje proces roboczy (najnowsza wersja klubu-tenanta meczu,
 *      `matches.club_id`), i ostrzeżenie, gdy raport należy do innego klubu
 *      niż mecz (`reports.club_id ≠ matches.club_id`).
 *
 * `--nieaktualne` (golden layout W1): wyłącznie raporty wyrenderowane INNĄ
 * wersją silnika niż wdrożona — wersja rośnie przy każdej zmianie szablonu v21
 * i silnika (CLAUDE.md §7), więc to ten sam zbiór co „sprzed ostatniej zmiany".
 * Bez `--club`/`--match` obejmuje wszystkie kluby: tak podpowiada `deploy.sh`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PO CO TO ISTNIEJE — WDROŻENIE PIVOTU „viewer".
 *
 * Migracje 014–017 zakładają PUSTE tabele: `events`, `tag_catalog`,
 * `match_players`. Wypełnia je dopiero silnik, przy generowaniu raportu
 * (`--out-events`, `meta.dictionary`). Mecze zaimportowane przed wdrożeniem
 * mają więc raporty na dysku, ale ZERO wierszy w tabeli zdarzeń — a na niej
 * stoi wszystko, co doszło w pivocie: metryki, pulpit, menu sezonowe,
 * kafelek zawodników.
 *
 * Bez tego kroku klient po wdrożeniu widzi puste liczby przy komplecie
 * raportów i ma prawo uznać, że coś się zepsuło.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * CO TO ROBI, A CZEGO NIE
 *
 * Kolejkuje zadania w istniejącej kolejce (`jobs`) — tej samej, której używa
 * przycisk „Przelicz raporty klubu". Nie uruchamia silnika, nie czyta CSV,
 * nie dotyka plików raportów. Wykonuje je cron (`app/bin/run_job.php`),
 * pojedynczo, w swoim tempie.
 *
 * Dlaczego nie „od razu": PHP-FPM na lh.pl ma `proc_open` na liście
 * `disable_functions` (docs/OGRANICZENIA_HOSTINGU.md), a i z CLI regeneracja
 * dwudziestu meczów w jednym procesie to dwadzieścia minut bez informacji
 * zwrotnej. Kolejka pokazuje postęp i przeżywa przerwane połączenie SSH.
 *
 * RÓŻNICA WOBEC PRZYCISKU W PANELU: `Rebuilds::queueClub()` bierze raporty
 * NIEAKTUALNE wobec templatu. Po wdrożeniu nieaktualny jest każdy — ale nie
 * dlatego, że templat urósł, tylko dlatego, że baza ma nowe tabele. Ten skrypt
 * kolejkuje WSZYSTKIE raporty klubu, niezależnie od numeru wersji.
 *
 * URUCHOMIENIE POWTÓRNE JEST BEZPIECZNE (app/repairs/README.md): raport
 * z zadaniem w toku jest pomijany, a ponowna regeneracja daje ten sam wynik.
 */

$root = dirname(__DIR__, 2);
require $root . '/app/src/bootstrap.php';

use CoachAnalyze\Clubs;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\Rebuilds;
use CoachAnalyze\ReportTemplates;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// ─────────────────────────────────────────────────────────────── argumenty
$argumenty = $argv ?? [];
$clubId = null;
$wszystkie = false;
$suchobieg = false;
$mecze = [];
$nieaktualne = false;
$zRodzenstwem = false;

for ($i = 1; $i < count($argumenty); $i++) {
    $a = (string) $argumenty[$i];
    if ($a === '--all') {
        $wszystkie = true;
    } elseif ($a === '--dry-run') {
        $suchobieg = true;
    } elseif ($a === '--nieaktualne') {
        $nieaktualne = true;
    } elseif ($a === '--z-rodzenstwem') {
        $zRodzenstwem = true;
    } elseif ($a === '--match') {
        $mecze[] = isset($argumenty[$i + 1]) ? (int) $argumenty[++$i] : 0;
    } elseif (preg_match('/^--match=(\d+)$/', $a, $m) === 1) {
        $mecze[] = (int) $m[1];
    } elseif ($a === '--club') {
        $clubId = isset($argumenty[$i + 1]) ? (int) $argumenty[++$i] : 0;
    } elseif (preg_match('/^--club=(\d+)$/', $a, $m) === 1) {
        $clubId = (int) $m[1];
    } else {
        fwrite(STDERR, "Nieznany argument: {$a}\n");
        exit(2);
    }
}

/*
 * ZAKRES JEST OBOWIĄZKOWY I MUSI BYĆ JAWNY.
 *
 * Skrypt bez argumentów NIE przyjmuje domyślnie „wszystko": na produkcji
 * z dwudziestoma meczami to dwadzieścia minut pracy crona, uruchomione przez
 * pomyłkę przy sprawdzaniu, czy plik w ogóle działa.
 */
if ($clubId === null && !$wszystkie && $mecze === [] && !$nieaktualne) {
    fwrite(STDERR, <<<TXT
    Podaj zakres:
      --club <ID>    raporty jednego klubu
      --all          raporty wszystkich klubów
      --match <ID>   raporty jednego meczu (można powtórzyć)
      --nieaktualne  raporty sprzed wdrożonej wersji silnika/szablonu

    Dodaj --dry-run, żeby zobaczyć listę bez kolejkowania.
    Domyślnie najnowszy raport meczu; --z-rodzenstwem bierze także starsze.

    TXT);
    exit(2);
}

if ($clubId !== null && $clubId <= 0) {
    fwrite(STDERR, "--club wymaga dodatniego identyfikatora klubu\n");
    exit(2);
}

foreach ($mecze as $mid) {
    if ($mid <= 0) {
        fwrite(STDERR, "--match wymaga dodatniego identyfikatora meczu\n");
        exit(2);
    }
}

// ─────────────────────────────────────────────────────────────── raporty
$parametry = [];
$warunki = [];
if ($mecze !== []) {
    $miejsca = [];
    foreach (array_values(array_unique($mecze)) as $i => $mid) {
        $miejsca[] = ':m' . $i;
        $parametry['m' . $i] = $mid;
    }
    $warunki[] = 'r.match_id IN (' . implode(', ', $miejsca) . ')';
}
if ($nieaktualne) {
    // Wersja WDROŻONA: artefakt z `deploy.sh`, a bez niego plik źródłowy.
    $wersja = \CoachAnalyze\Engine::version();
    if (preg_match('/^\d+\.\d+\.\d+/', $wersja) !== 1) {
        fwrite(STDERR, "Nie znam wdrożonej wersji silnika ({$wersja}) — --nieaktualne nie ma z czym porównać\n");
        exit(2);
    }
    $warunki[] = '(r.engine_version IS NULL OR r.engine_version <> :wersja)';
    $parametry['wersja'] = $wersja;
    echo "Wdrożony silnik: {$wersja} — biorę raporty wyrenderowane inną wersją.\n";
}
if ($clubId !== null) {
    $warunki[] = 'r.club_id = :club';
    $parametry['club'] = $clubId;

    if (Clubs::find($clubId) === null) {
        fwrite(STDERR, "Nie ma klubu o identyfikatorze {$clubId}\n");
        exit(2);
    }
}

if (!$zRodzenstwem) {
    // Najnowszy raport meczu — ta sama definicja „najnowszego" co lista raportów
    // (`Reports::search`, `jeden_na_mecz`) i pulpit.
    $warunki[] = 'r.id = (SELECT r2.id FROM reports r2 WHERE r2.match_id = r.match_id
                           ORDER BY r2.generated_at DESC, r2.id DESC LIMIT 1)';
}

$raporty = Db::all(
    "SELECT r.id, r.match_id, r.club_id, r.template_version,
            m.played_at, m.round, m.club_id AS tenant_id,
            c.name AS club_name, t.name AS tenant_name
       FROM reports r
       LEFT JOIN matches m ON m.id = r.match_id
       LEFT JOIN clubs   c ON c.id = r.club_id
       LEFT JOIN clubs   t ON t.id = m.club_id
      " . ($warunki === [] ? '' : 'WHERE ' . implode(' AND ', $warunki)) . "
      ORDER BY r.club_id, (m.played_at IS NULL), m.played_at, r.id",
    $parametry
);

if ($raporty === []) {
    echo "Brak raportów w tym zakresie — nie ma czego regenerować.\n";
    exit(0);
}

/*
 * UŻYTKOWNIK ODPOWIEDZIALNY ZA WPIS W DZIENNIKU. Skrypt chodzi z CLI, więc nie
 * ma sesji; bierzemy właściciela meczu, a gdy go nie ma — pierwsze konto.
 * `Audit` ma pokazywać, KTO to zrobił, a „null" nie jest odpowiedzią.
 */
$konto = Db::one('SELECT id FROM users ORDER BY id LIMIT 1');
$userId = $konto !== null ? (int) $konto['id'] : 0;

$naglowek = match (true) {
    $mecze !== []    => 'Mecze ' . implode(', ', $mecze) . ': ' . count($raporty) . ' raportów',
    $clubId !== null => "Klub {$clubId}: " . count($raporty) . ' raportów',
    default          => 'Wszystkie kluby: ' . count($raporty) . ' raportów',
} . ($nieaktualne ? ' (nieaktualne)' : '');
echo $naglowek . ($suchobieg ? "  [SUCHOBIEG — nic nie zakolejkuję]\n" : "\n");
echo str_repeat('─', 72) . "\n";

$zakolejkowane = 0;
$pominiete = [];
$rozjazdy = 0;
// Jedna partia na przebieg — jedno podsumowanie zamiast chmurki na raport.
$partia = Rebuilds::newBatchId();

/** @var array<int,int> $wersjeTemplatu  club_id => wersja, której użyje proces roboczy */
$wersjeTemplatu = [];

foreach ($raporty as $r) {
    /*
     * WERSJA TEMPLATU, KTÓREJ UŻYJE PROCES ROBOCZY — to samo wywołanie co
     * `uruchomSilnik()` w run_job.php (`ReportTemplates::current` klubu-tenanta
     * meczu). Wypisana PRZED kolejkowaniem, żeby „dlaczego raport ma templat v1"
     * miało odpowiedź na ekranie, a nie po dwudziestu minutach pracy crona.
     */
    $tenant = $r['tenant_id'] !== null ? (int) $r['tenant_id'] : 0;
    if ($tenant > 0 && !isset($wersjeTemplatu[$tenant])) {
        $wersjeTemplatu[$tenant] = ReportTemplates::currentVersion($tenant);
    }
    $wTpl = $wersjeTemplatu[$tenant] ?? 0;
    $opis = sprintf(
        '  raport %-5d mecz %-5d %-11s %s  → %s',
        (int) $r['id'],
        (int) $r['match_id'],
        (string) ($r['played_at'] ?? 'bez daty'),
        (string) ($r['tenant_name'] ?? '?'),
        $wTpl > 0 ? 'templat v' . $wTpl : 'bez templatu'
    );
    // Raport zapisany pod innym klubem niż tenant meczu (np. po przepięciu
    // meczu, PorzadkiMeczow::planPrzepnij): przeliczenie weźmie templat
    // TENANTA, a lista raportów porównywała dotąd z klubem raportu.
    if ($r['club_id'] !== null && (int) $r['club_id'] !== $tenant) {
        $rozjazdy++;
        $opis .= sprintf('  ! raport klubu %d (%s), mecz klubu %d',
            (int) $r['club_id'], (string) ($r['club_name'] ?? '?'), $tenant);
    }

    if ($suchobieg) {
        /*
         * SUCHOBIEG SPRAWDZA TO SAMO, CO ZAPIS: brak surowych plików jest
         * powodem, dla którego regeneracja się nie uda, i operator ma go
         * zobaczyć TERAZ, a nie po dwudziestu minutach pracy crona.
         */
        $import = Imports::latestForMatch((int) $r['match_id']);
        $gotowy = Imports::rawUsable($import);
        echo $opis . ($gotowy ? "\n" : "   ← BRAK SUROWYCH PLIKÓW\n");
        if (!$gotowy) {
            $pominiete[] = (int) $r['id'];
        }
        continue;
    }

    $wynik = Rebuilds::queue((int) $r['id'], $userId, $partia);
    if (!empty($wynik['ok'])) {
        $zakolejkowane++;
        echo $opis . "   → zakolejkowany\n";
        continue;
    }

    $pominiete[] = (int) $r['id'];
    echo $opis . '   ← pominięty: ' . (string) $wynik['error'] . "\n";
}

echo str_repeat('─', 72) . "\n";

if ($rozjazdy > 0) {
    echo "UWAGA: {$rozjazdy} raport(ów) należy do innego klubu niż mecz — przeliczenie użyje\n";
    echo "templatu klubu MECZU (matches.club_id). Sprawdź, czy mecz nie został przepięty.\n";
}

if ($suchobieg) {
    printf("Do zakolejkowania: %d, bez surowych plików: %d\n",
        count($raporty) - count($pominiete), count($pominiete));
    echo "Uruchom bez --dry-run, żeby faktycznie zakolejkować.\n";
    exit(0);
}

printf("Zakolejkowano: %d, pominięto: %d\n", $zakolejkowane, count($pominiete));

if ($zakolejkowane > 0) {
    echo "Partia {$partia}: po ostatnim zadaniu jedno powiadomienie zbiorcze, bez maili.\n";
    echo "Zadania podnosi cron (app/bin/run_job.php), pojedynczo, co minutę.\n";
    echo "Postęp: panel → Raporty, albo:\n";
    echo "  SELECT status, COUNT(*) FROM jobs WHERE type = 'rebuild_report' GROUP BY status;\n";
}

exit(0);
