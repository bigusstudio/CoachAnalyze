<?php
declare(strict_types=1);

/**
 * Regeneracja raportów istniejących meczów — KOLEJKOWANIE, nie liczenie.
 *
 *   php app/repairs/regeneruj_raporty.php --club 3 [--dry-run]
 *   php app/repairs/regeneruj_raporty.php --all    [--dry-run]
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

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// ─────────────────────────────────────────────────────────────── argumenty
$argumenty = $argv ?? [];
$clubId = null;
$wszystkie = false;
$suchobieg = false;

for ($i = 1; $i < count($argumenty); $i++) {
    $a = (string) $argumenty[$i];
    if ($a === '--all') {
        $wszystkie = true;
    } elseif ($a === '--dry-run') {
        $suchobieg = true;
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
if ($clubId === null && !$wszystkie) {
    fwrite(STDERR, <<<TXT
    Podaj zakres:
      --club <ID>   raporty jednego klubu
      --all         raporty wszystkich klubów

    Dodaj --dry-run, żeby zobaczyć listę bez kolejkowania.

    TXT);
    exit(2);
}

if ($clubId !== null && $clubId <= 0) {
    fwrite(STDERR, "--club wymaga dodatniego identyfikatora klubu\n");
    exit(2);
}

// ─────────────────────────────────────────────────────────────── raporty
$parametry = [];
$warunek = '';
if ($clubId !== null) {
    $warunek = 'WHERE r.club_id = :club';
    $parametry['club'] = $clubId;

    if (Clubs::find($clubId) === null) {
        fwrite(STDERR, "Nie ma klubu o identyfikatorze {$clubId}\n");
        exit(2);
    }
}

$raporty = Db::all(
    "SELECT r.id, r.match_id, r.club_id, r.template_version,
            m.played_at, m.round, c.name AS club_name
       FROM reports r
       LEFT JOIN matches m ON m.id = r.match_id
       LEFT JOIN clubs   c ON c.id = r.club_id
      {$warunek}
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

$naglowek = $clubId !== null
    ? "Klub {$clubId}: " . count($raporty) . " raportów"
    : 'Wszystkie kluby: ' . count($raporty) . ' raportów';
echo $naglowek . ($suchobieg ? "  [SUCHOBIEG — nic nie zakolejkuję]\n" : "\n");
echo str_repeat('─', 72) . "\n";

$zakolejkowane = 0;
$pominiete = [];

foreach ($raporty as $r) {
    $opis = sprintf(
        '  raport %-5d mecz %-5d %-11s %s',
        (int) $r['id'],
        (int) $r['match_id'],
        (string) ($r['played_at'] ?? 'bez daty'),
        (string) ($r['club_name'] ?? '?')
    );

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

    $wynik = Rebuilds::queue((int) $r['id'], $userId);
    if (!empty($wynik['ok'])) {
        $zakolejkowane++;
        echo $opis . "   → zakolejkowany\n";
        continue;
    }

    $pominiete[] = (int) $r['id'];
    echo $opis . '   ← pominięty: ' . (string) $wynik['error'] . "\n";
}

echo str_repeat('─', 72) . "\n";

if ($suchobieg) {
    printf("Do zakolejkowania: %d, bez surowych plików: %d\n",
        count($raporty) - count($pominiete), count($pominiete));
    echo "Uruchom bez --dry-run, żeby faktycznie zakolejkować.\n";
    exit(0);
}

printf("Zakolejkowano: %d, pominięto: %d\n", $zakolejkowane, count($pominiete));

if ($zakolejkowane > 0) {
    echo "Zadania podnosi cron (app/bin/run_job.php), pojedynczo, co minutę.\n";
    echo "Postęp: panel → Raporty, albo:\n";
    echo "  SELECT status, COUNT(*) FROM jobs WHERE type = 'rebuild_report' GROUP BY status;\n";
}

exit(0);
