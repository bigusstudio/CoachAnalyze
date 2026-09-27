<?php
declare(strict_types=1);

/**
 * Porządki w meczach i klubach: usunięcie meczu, przepięcie na inny klub,
 * usunięcie pustego klubu, przycięcie raportów meczu.
 *
 *   php app/repairs/usun_mecz.php --match 14 --match 24             # podgląd
 *   php app/repairs/usun_mecz.php --przepnij-klub 8 --przepnij-klub 10 --na 3
 *   php app/repairs/usun_mecz.php --usun-klub 12
 *   php app/repairs/usun_mecz.php --raporty-tylko-najnowszy 3
 *   … --zapisz                                                      # wykonanie
 *
 * Opcje:
 *   --match N                  usuwa mecz N: raporty (+ pliki, linki publiczne,
 *                              powiadomienia), importy (+ pliki uploadów), zadania
 *                              kolejki (+ katalogi robocze), zdarzenia, skład,
 *                              notatki. Katalog tagów klubu zostaje (historia) —
 *                              traci tylko wskazanie na usunięty import.
 *   --przepnij-klub N --na K   mecz N przechodzi do klubu K: `club_id`, a także
 *                              `club_home_id`/`club_away_id`, jeśli wskazywały
 *                              stary klub. Raporty i skład zostają bez zmian.
 *   --usun-klub K              tylko klub, do którego nie prowadzi żaden mecz,
 *                              raport, link ani skład (poza usuwanymi i
 *                              przepinanymi w tym samym przebiegu)
 *   --raporty-tylko-najnowszy N  zostawia najnowszy raport meczu N, resztę usuwa
 *   --zapisz                   wykonuje; bez tego wyłącznie podgląd
 *
 * Każdą opcję poza --na i --zapisz można podać wiele razy. Kolejność wykonania
 * jest stała: przepięcia, usunięcia meczów, przycięcia raportów, usunięcia klubów.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PODGLĄD POKAZUJE DOKŁADNIE TO, CO ZROBI ZAPIS (`app/src/PorzadkiMeczow.php`):
 * każde zapytanie z liczbą wierszy i każdy plik. Zapytania idą w jednej
 * transakcji; pliki są usuwane po commicie i wyłącznie wewnątrz STORAGE_PATH.
 *
 * ZRZUT BAZY PRZED ZAPISEM (app/repairs/README.md, zasada 4). Skrypt wypisuje
 * komendę `mysqldump` i przy `--zapisz` wymaga potwierdzenia, że zrzut jest
 * zrobiony (`--mam-zrzut`). Usunięcia nie da się cofnąć inaczej niż zrzutem —
 * pliki raportów i uploadów znikają z dysku.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * POWTÓRZENIE JEST BEZPIECZNE: usunięty mecz nie istnieje, więc drugi przebieg
 * kończy się błędem „nie istnieje" bez zmian; przepięty mecz nie ma czego
 * przepinać; przycięty mecz ma jeden raport.
 */

$root = dirname(__DIR__, 2);
require $root . '/app/src/bootstrap.php';

use CoachAnalyze\Config;
use CoachAnalyze\PorzadkiMeczow;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// ─────────────────────────────────────────────────────────────── argumenty
$mecze = [];
$przepnij = [];
$na = null;
$kluby = [];
$tylkoNajnowszy = [];
$zapisz = false;
$mamZrzut = false;

$argumenty = $argv ?? [];
$liczbowe = ['--match', '--przepnij-klub', '--na', '--usun-klub', '--raporty-tylko-najnowszy'];
for ($i = 1; $i < count($argumenty); $i++) {
    $a = (string) $argumenty[$i];
    $wartosc = 0;
    if (preg_match('/^(--[a-z-]+)=(.*)$/', $a, $m) === 1) {
        [$a, $surowa] = [$m[1], $m[2]];
    } elseif (in_array($a, $liczbowe, true)) {
        $surowa = (string) ($argumenty[++$i] ?? '');
    } else {
        $surowa = '';
    }
    if (in_array($a, $liczbowe, true)) {
        $wartosc = ctype_digit($surowa) ? (int) $surowa : 0;
    }
    switch ($a) {
        case '--match':                   $mecze[] = $wartosc; break;
        case '--przepnij-klub':           $przepnij[] = $wartosc; break;
        case '--na':                      $na = $wartosc; break;
        case '--usun-klub':               $kluby[] = $wartosc; break;
        case '--raporty-tylko-najnowszy': $tylkoNajnowszy[] = $wartosc; break;
        case '--zapisz':                  $zapisz = true; break;
        case '--mam-zrzut':               $mamZrzut = true; break;
        default:
            fwrite(STDERR, "Nieznany argument: {$a}\n");
            exit(2);
    }
}

foreach (array_merge($mecze, $przepnij, $kluby, $tylkoNajnowszy) as $id) {
    if ($id <= 0) {
        fwrite(STDERR, "Każda opcja wymaga dodatniego numeru.\n");
        exit(2);
    }
}
if ($przepnij !== [] && ($na === null || $na <= 0)) {
    fwrite(STDERR, "--przepnij-klub wymaga --na K.\n");
    exit(2);
}
if (array_intersect($mecze, $przepnij) !== [] || array_intersect($mecze, $tylkoNajnowszy) !== []) {
    fwrite(STDERR, "Ten sam mecz nie może być jednocześnie usuwany i przepinany/przycinany.\n");
    exit(2);
}
if ($mecze === [] && $przepnij === [] && $kluby === [] && $tylkoNajnowszy === []) {
    fwrite(STDERR, "Nic do zrobienia — podaj --match, --przepnij-klub, --usun-klub albo --raporty-tylko-najnowszy.\n");
    exit(2);
}

// ─────────────────────────────────────────────────────────────── plan
$plany = [];
foreach ($przepnij as $m) {
    $plany[] = PorzadkiMeczow::planPrzepnij($m, (int) $na);
}
if ($mecze !== []) {
    $plany[] = PorzadkiMeczow::planUsunMecze($mecze);
}
foreach ($tylkoNajnowszy as $m) {
    $plany[] = PorzadkiMeczow::planTylkoNajnowszy($m);
}
foreach ($kluby as $k) {
    $plany[] = PorzadkiMeczow::planUsunKlub($k, $mecze, $przepnij);
}
$plan = PorzadkiMeczow::scal(...$plany);

echo $zapisz ? "TRYB ZAPISU\n" : "PODGLĄD (bez --zapisz nic nie zostanie zmienione)\n";

if ($plan['bledy'] !== []) {
    echo "\nNIE MOŻNA WYKONAĆ:\n";
    foreach ($plan['bledy'] as $b) {
        echo "  ✗ {$b}\n";
    }
    exit(1);
}

echo "\nRekordy:\n";
foreach ($plan['zapytania'] as $z) {
    printf("  %5d  %s\n", $z['ile'], $z['opis']);
}
if ($plan['zapytania'] === []) {
    echo "  (brak)\n";
}
echo "\nPliki (" . count($plan['pliki']) . "):\n";
foreach ($plan['pliki'] as $f) {
    echo '  ' . $f . (is_file($f) ? '' : '  [brak na dysku]') . "\n";
}
foreach ($plan['katalogi'] as $d) {
    echo '  ' . $d . '/' . (is_dir($d) ? '' : '  [brak na dysku]') . "\n";
}
if ($plan['uwagi'] !== []) {
    echo "\nUwagi:\n";
    foreach ($plan['uwagi'] as $u) {
        echo "  ! {$u}\n";
    }
}

$baza = (string) (Config::get('DB_NAME') ?? 'BAZA');
printf(
    "\nPrzed zapisem zrób zrzut bazy:\n  mysqldump --single-transaction --quick --default-character-set=utf8mb4 %s > ~/CoachAnalyze/shared/backups/przed-usun_mecz-%s.sql\n",
    escapeshellarg($baza), date('Ymd-His')
);

if (!$zapisz) {
    exit(0);
}
if ($plan['zapytania'] === [] && $plan['pliki'] === [] && $plan['katalogi'] === []) {
    echo "\nNic do zrobienia.\n";
    exit(0);
}
if (!$mamZrzut) {
    echo "\nZapis wstrzymany: po wykonaniu zrzutu uruchom ponownie z --zapisz --mam-zrzut.\n";
    exit(1);
}

$wynik = PorzadkiMeczow::wykonaj($plan);
echo "\nWykonano: " . count($plan['zapytania']) . " zapytań, usunięto plików: {$wynik['pliki']}\n";
foreach ($wynik['pominiete'] as $p) {
    echo "  ! pominięto {$p}\n";
}
exit(0);
