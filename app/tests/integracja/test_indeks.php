<?php
declare(strict_types=1);

/**
 * Indeks współczynników (M1): hasła domyślne, wersje klubowe, wyszukiwarka.
 *
 * Uruchomienie:  php test_indeks.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\IndexTerms;
use CoachAnalyze\Mappings;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$baza    = $here . '/indeks.sqlite';
$envFile = $here . '/.env.indeks';
@unlink($baza);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $here, 'APP_URL=https://app.example.test',
    'SESSION_NAME=ca_test', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza, false);

Db::run("INSERT INTO clubs (id, owner_id, club_key, name, is_own_team, created_at)
         VALUES (1, 1, 'HUT7K2QX', 'Klub A', 1, :t)", ['t' => Stats::now()]);

// ---------------------------------------------------------------- hasła domyślne
echo "== hasła domyślne: komplet pól i pojęcia z listy zamkniętej ==\n";

check('jest co najmniej 11 haseł', count(IndexTerms::DOMYSLNE) >= 11,
    (string) count(IndexTerms::DOMYSLNE));

$wymagane = ['concept', 'name', 'definition', 'formula', 'example', 'interpretation', 'source'];
$braki = [];
foreach (IndexTerms::DOMYSLNE as $slug => $haslo) {
    foreach ($wymagane as $pole) {
        if (trim((string) ($haslo[$pole] ?? '')) === '') {
            $braki[] = "{$slug}.{$pole}";
        }
    }
    if (!in_array($haslo['concept'], Mappings::POJECIA, true)) {
        $braki[] = "{$slug}: pojęcie spoza listy ({$haslo['concept']})";
    }
    if (preg_match('/^[a-z0-9-]+$/', (string) $slug) !== 1) {
        $braki[] = "{$slug}: slug spoza [a-z0-9-]";
    }
}
check('każde hasło ma komplet pól i poprawne pojęcie', $braki === [], implode(', ', $braki));

check('xG jest oznaczone jako szacowane, z zastrzeżeniem o kalibracji',
    str_contains((string) IndexTerms::DOMYSLNE['xg']['estimated_note'], 'porównawczo'));

// ---------------------------------------------------------------- hasło efektywne
echo "\n== hasło efektywne: klubowa wersja wygrywa z domyślną ==\n";

$domyslne = IndexTerms::find(1, 'pressing');
check('bez wersji klubowej wraca domyślne', $domyslne !== null && !empty($domyslne['is_default']));
check('nieznany slug daje null', IndexTerms::find(1, 'nie-ma-takiego') === null);

$id1 = IndexTerms::saveVersion(1, 'pressing', [
    'name'       => 'Pressing (metodyka klubu)',
    'definition' => 'Doskok w strefie średniej wedle założeń sztabu.',
    'formula'    => 'skuteczny / wszystkie',
], 1);
check('zapis zwraca identyfikator', $id1 > 0);

$poZapisie = IndexTerms::find(1, 'pressing');
check('wersja klubowa wygrywa', $poZapisie !== null && empty($poZapisie['is_default'])
    && $poZapisie['name'] === 'Pressing (metodyka klubu)');
check('wersja policzona od 1', (int) $poZapisie['version'] === 1);
check('pojęcie przepisane z hasła, nie z formularza',
    $poZapisie['concept'] === 'press',
    'hasło nie może odpłynąć do innego pojęcia');

IndexTerms::saveVersion(1, 'pressing', [
    'name'       => 'Pressing v2',
    'definition' => 'Nowa definicja.',
], 1);
check('kolejny zapis podbija wersję', (int) IndexTerms::find(1, 'pressing')['version'] === 2);
check('poprzednia wersja ZOSTAJE',
    (int) Db::one('SELECT COUNT(*) AS ile FROM index_terms WHERE club_id = 1 AND slug = :s',
        ['s' => 'pressing'])['ile'] === 2,
    'historia metodyki musi być odtwarzalna');

$innyKlub = IndexTerms::find(2, 'pressing');
check('inny klub nadal widzi domyślne', $innyKlub !== null && !empty($innyKlub['is_default']));

// ---------------------------------------------------------------- walidacja
echo "\n== walidacja zapisu ==\n";

$blad = null;
try {
    IndexTerms::saveVersion(1, 'pressing', ['name' => '', 'definition' => 'x'], 1);
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}
check('puste pola obowiązkowe przerywają zapis', $blad !== null);

$blad2 = null;
try {
    IndexTerms::saveVersion(1, 'nie-ma-takiego', ['name' => 'X', 'definition' => 'Y'], 1);
} catch (\Throwable $e) {
    $blad2 = $e->getMessage();
}
check('zapis do nieistniejącego hasła odrzucony', $blad2 !== null);

// ---------------------------------------------------------------- wyszukiwarka
echo "\n== wyszukiwarka po nazwie i treści ==\n";

check('puste zapytanie zwraca wszystko',
    count(IndexTerms::search(1, '')) === count(IndexTerms::DOMYSLNE));
$poNazwie = IndexTerms::search(1, 'celność');
check('szuka po nazwie', count($poNazwie) === 1 && $poNazwie[0]['slug'] === 'celnosc');
$poTresci = IndexTerms::search(1, 'strefę bezpośredniego zagrożenia');
check('szuka po treści definicji', $poTresci !== []
    && in_array('sbz', array_column($poTresci, 'slug'), true));
check('wersja klubowa jest przeszukiwana zamiast domyślnej',
    array_column(IndexTerms::search(1, 'Pressing v2'), 'slug') === ['pressing']);
check('brak trafień daje pustą listę', IndexTerms::search(1, 'żabka wodna') === []);

// ---------------------------------------------------------------- odsyłacze renderu
echo "\n== odsyłacze dla renderu ==\n";

$linki = IndexTerms::linksFor(1);
check('lista odsyłaczy kompletna', count($linki) === count(IndexTerms::DOMYSLNE));
$xg = null;
foreach ($linki as $l) {
    if ($l['slug'] === 'xg') {
        $xg = $l;
    }
}
check('xG oznaczone jako szacowane', $xg !== null && $xg['estimated'] === true);
check('etykieta z hasła efektywnego',
    in_array('Pressing v2', array_column($linki, 'label'), true));

// ---------------------------------------------------------------- teksty
echo "\n== teksty interfejsu ==\n";
$brakTekstow = [];
foreach ([
    'nav.index', 'index.title', 'index.intro', 'index.search', 'index.filter',
    'index.none', 'index.col.name', 'index.col.concept', 'index.col.version',
    'index.version.default', 'index.version.club', 'index.estimated',
    'index.field.definition', 'index.field.formula', 'index.field.example',
    'index.field.interpretation', 'index.field.source', 'index.field.estimated_note',
    'index.edit', 'index.edit.title', 'index.edit.why', 'index.edit.versioning',
    'index.edit.submit', 'index.saved', 'index.err.missing', 'index.err.no_club',
    'index.err.save', 'index.public.subtitle',
] as $klucz) {
    if (View::t($klucz) === $klucz) {
        $brakTekstow[] = $klucz;
    }
}
check('wszystkie klucze mają polskie teksty', $brakTekstow === [], implode(', ', $brakTekstow));

@unlink($baza);
@unlink($envFile);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
