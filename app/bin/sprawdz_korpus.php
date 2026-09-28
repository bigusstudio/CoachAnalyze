<?php
declare(strict_types=1);

/**
 * Przegląd korpusu eksportów LiveTag przez silnik — BEZ ZAPISU DO BAZY (W7 H).
 *
 *   php app/bin/sprawdz_korpus.php KATALOG [KATALOG …] [--python=ŚCIEŻKA] [--json]
 *
 * Dla każdego katalogu (rekurencyjnie):
 *   1. paruje CSV z JSON PO ZAWARTOŚCI — nazwy tagów z pliku projektu muszą
 *      pokryć `tag_name` z CSV; remis: ten sam katalog, potem najbliższy czas
 *      zapisu (nazwy plików na serwerze są losowe, więc nazwa nic nie mówi),
 *   2. deduplikuje mecze po skrócie zdarzeń niezależnym od kolejności wierszy
 *      (`Upload::sha256Zdarzen`, bliźniak `parse.sha256_zdarzen`),
 *   3. przepuszcza każdy mecz przez `coachanalyze build` do katalogu
 *      tymczasowego (raport v21, meta) — baza nie jest dotykana,
 *   4. grupuje mecze w profile analityka (Jaccard UUID albo nazw ≥ 0,8,
 *      `ProfilAnalityka`) i drukuje tabelę.
 *
 * WYJŚCIE BEZ NAZWISK I TREŚCI KOMENTARZY: mecz to skrót zdarzeń i nazwy
 * drużyn z kolumny `team`; anomalie — typy i liczby, bez opisów.
 * Niezmienniki: te, które sprawdza silnik (wiersze, tagi w warstwie 1,
 * zawodnicy w zdarzeniach, xG, kierunek). Niezmienniki bazy i ekranu
 * Zawodnicy wymagają zapisu — sprawdza je zadanie budowy raportu.
 *
 * Kod wyjścia: 0 — wszystkie niezmienniki zachowane; 1 — naruszenie albo
 * błąd silnika; 2 — zły argument.
 */

namespace CoachAnalyze;

$katalogi = [];
$python = null;
$jakoJson = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--python=')) {
        $python = substr($arg, 9);
    } elseif ($arg === '--json') {
        $jakoJson = true;
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Nieznana opcja: {$arg}\n");
        exit(2);
    } else {
        $katalogi[] = rtrim($arg, '/');
    }
}
if ($katalogi === []) {
    fwrite(STDERR, "Użycie: php app/bin/sprawdz_korpus.php KATALOG [KATALOG …] [--python=ŚCIEŻKA] [--json]\n");
    exit(2);
}

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/ca_korpus_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

/*
 * Konfiguracja bez `.env` (lokalnie go nie ma): tymczasowy plik z samym
 * PYTHON_BIN i generacją szablonu. Na serwerze, bez `--python`, obowiązuje
 * zwykły `.env`. Baza nie jest potrzebna — `Db` łączy się leniwie.
 */
$zPliku = null;
if ($python !== null || !is_file($repo . '/.env')) {
    $python ??= is_file($repo . '/venv/bin/python') ? $repo . '/venv/bin/python' : 'python3';
    $zPliku = $tmp . '/.env';
    file_put_contents($zPliku, "PYTHON_BIN={$python}\nHTML_TEMPLATE=v21\nENGINE_TIMEOUT=180\n");
    putenv('CA_ENV_PATH=' . $zPliku);
}
// Silnik bywa niezainstalowany w venv (tak jest lokalnie) — pakiet z repozytorium.
putenv('PYTHONPATH=' . $repo . '/engine' . ((string) getenv('PYTHONPATH') !== '' ? ':' . getenv('PYTHONPATH') : ''));

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/EngineRunner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

register_shutdown_function(static function () use ($tmp): void {
    exec('rm -rf ' . escapeshellarg($tmp));
});

// ------------------------------------------------------------------ pliki
$csvy = [];
$jsony = [];
foreach ($katalogi as $k) {
    if (!is_dir($k)) {
        fwrite(STDERR, "Nie ma katalogu: {$k}\n");
        exit(2);
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($k, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $plik) {
        $ext = strtolower($plik->getExtension());
        if ($ext === 'csv') {
            $csvy[] = $plik->getPathname();
        } elseif ($ext === 'json') {
            $jsony[] = $plik->getPathname();
        }
    }
}
sort($csvy);
sort($jsony);

/** @return array{tagi:list<string>, druzyny:list<string>}|null */
function projekt(string $sciezka): ?array
{
    $d = json_decode((string) @file_get_contents($sciezka), true);
    if (!is_array($d) || !is_array($d['dependencies'] ?? null)) {
        return null;
    }
    $tagi = [];
    $druzyny = [];
    foreach ($d['dependencies'] as $dep) {
        $nazwa = (string) ($dep['data']['name'] ?? '');
        if (($dep['type'] ?? '') === 'tag' && $nazwa !== '') {
            $tagi[$nazwa] = true;
        } elseif (($dep['type'] ?? '') === 'team' && $nazwa !== '') {
            $druzyny[] = $nazwa;
        }
    }
    return ['tagi' => array_keys($tagi), 'druzyny' => $druzyny];
}

/** @return array{tagi:list<string>, druzyny:list<string>} nazwy tagów i drużyn z CSV */
function zawartoscCsv(string $sciezka): array
{
    $h = fopen($sciezka, 'rb');
    if (fread($h, 3) !== "\xEF\xBB\xBF") {
        rewind($h);
    }
    $nag = fgetcsv($h, 0, ',', '"', '') ?: [];
    $iTag = array_search('tag_name', $nag, true);
    $iTeam = array_search('team', $nag, true);
    $tagi = [];
    $druzyny = [];
    while (($w = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($w === [null]) {
            continue;
        }
        if ($iTag !== false && isset($w[$iTag])) {
            $tagi[(string) $w[$iTag]] = true;
        }
        if ($iTeam !== false && trim((string) ($w[$iTeam] ?? '')) !== '') {
            $druzyny[trim((string) $w[$iTeam])] = true;
        }
    }
    fclose($h);
    return ['tagi' => array_keys($tagi), 'druzyny' => array_keys($druzyny)];
}

$projekty = [];
foreach ($jsony as $j) {
    $p = projekt($j);
    if ($p !== null) {
        $projekty[$j] = $p;
    }
}

$znaneDruzyny = [];
$pary = [];
$bezPary = [];
foreach ($csvy as $c) {
    $z = zawartoscCsv($c);
    foreach ($z['druzyny'] as $d) {
        $znaneDruzyny[$d] = true;
    }
    $kandydaci = [];
    foreach ($projekty as $j => $p) {
        if (array_diff($z['tagi'], $p['tagi']) === []) {
            $kandydaci[] = $j;
        }
    }
    if ($kandydaci === []) {
        $bezPary[] = $c;
        continue;
    }
    $mt = filemtime($c);
    usort($kandydaci, static fn(string $a, string $b): int =>
        [dirname($a) !== dirname($c), abs(filemtime($a) - $mt)]
        <=> [dirname($b) !== dirname($c), abs(filemtime($b) - $mt)]);
    $pary[] = [$c, $kandydaci[0]];
}
foreach ($projekty as $p) {
    foreach ($p['druzyny'] as $d) {
        $znaneDruzyny[$d] = true;
    }
}

// ------------------------------------------------------------ deduplikacja
$mecze = [];
foreach ($pary as [$c, $j]) {
    $skrot = Upload::sha256Zdarzen($c);
    if ($skrot === null) {
        $bezPary[] = $c;
        continue;
    }
    $mecze[$skrot] ??= ['csv' => $c, 'json' => $j, 'wgran' => 0];
    $mecze[$skrot]['wgran']++;
}

// ------------------------------------------------------------ silnik
$config = $tmp . '/config.json';
file_put_contents($config, json_encode([
    'match_id' => null,
    'znane_druzyny' => array_keys($znaneDruzyny),
], JSON_UNESCAPED_UNICODE));

$wiersze = [];
$zle = 0;
foreach ($mecze as $skrot => $m) {
    $meta = $tmp . '/' . substr($skrot, 0, 12) . '.json';
    $wynik = EngineRunner::build([
        'csv' => $m['csv'], 'json' => $m['json'], 'config' => $config,
        'html_template' => 'v21',
        'out_html' => $tmp . '/' . substr($skrot, 0, 12) . '.html',
        'out_meta' => $meta,
    ]);
    $dane = json_decode((string) @file_get_contents($meta), true);
    if ($wynik['exit'] !== 0 || !is_array($dane) || empty($dane['ok'])) {
        $zle++;
        $wiersze[] = ['mecz' => substr($skrot, 0, 12), 'blad' => 'silnik: kod ' . $wynik['exit']];
        continue;
    }
    $anomalie = [];
    foreach ((array) ($dane['anomalie'] ?? []) as $a) {
        $anomalie[(string) $a['typ']] = ($anomalie[(string) $a['typ']] ?? 0) + 1;
    }
    $naruszone = array_values(array_map(
        static fn(array $n): string => (string) $n['kod'],
        array_filter((array) ($dane['niezmienniki'] ?? []), static fn($n): bool => empty($n['ok']))
    ));
    if ($naruszone !== []) {
        $zle++;
    }
    $wiersze[] = [
        'mecz'          => substr($skrot, 0, 12),
        'druzyny'       => (array) ($dane['coverage']['teams'] ?? []),
        'livetag'       => $dane['wersja_livetag'] ?? null,
        'profil_odcisk' => $dane['profil'] ?? null,
        'wgran'         => $m['wgran'],
        'zdarzenia'     => (int) ($dane['coverage']['events'] ?? 0),
        'tagi'          => count((array) ($dane['znaczenia'] ?? [])),
        'niezmienniki'  => $naruszone === [] ? 'OK' : 'błąd: ' . implode(', ', $naruszone),
        'nierozpoznane' => count((array) ($dane['nierozpoznane'] ?? [])),
        'anomalie'      => $anomalie,
    ];
}

// ---------------------------------------------------- profile (Jaccard ≥ 0,8)
$profile = [];   // indeks profilu => lista indeksów wierszy
foreach ($wiersze as $i => $w) {
    if (!isset($w['profil_odcisk'])) {
        continue;
    }
    $przydzial = null;
    foreach ($profile as $p => $czlonkowie) {
        foreach ($czlonkowie as $inny) {
            $o = $wiersze[$inny]['profil_odcisk'];
            if (ProfilAnalityka::jaccard($w['profil_odcisk']['uuid'], $o['uuid']) >= ProfilAnalityka::PROG
                || ProfilAnalityka::jaccard($w['profil_odcisk']['nazwy'], $o['nazwy']) >= ProfilAnalityka::PROG) {
                $przydzial = $p;
                break 2;
            }
        }
    }
    if ($przydzial === null) {
        $przydzial = count($profile);
        $profile[$przydzial] = [];
    }
    $profile[$przydzial][] = $i;
    $wiersze[$i]['profil'] = $przydzial;
}
foreach ($wiersze as &$w) {
    unset($w['profil_odcisk']);
}
unset($w);

if ($jakoJson) {
    echo json_encode([
        'par' => count($pary), 'csv_bez_pary' => count($bezPary),
        'meczow' => count($mecze), 'profili' => count($profile), 'mecze' => $wiersze,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit($zle === 0 ? 0 : 1);
}

printf("Par CSV+JSON: %d · CSV bez pary: %d · unikalnych meczów (po zdarzeniach): %d · profili analityka: %d\n\n",
    count($pary), count($bezPary), count($mecze), count($profile));
printf("%-12s  %-52s  %-6s  %-5s  %-9s  %-4s  %-14s  %-13s  %s\n",
    'mecz', 'drużyny (kolumna team)', 'profil', 'wgrań', 'zdarzenia', 'tagi', 'niezmienniki', 'nierozpoznane', 'anomalie');
foreach ($wiersze as $w) {
    if (isset($w['blad'])) {
        printf("%-12s  %s\n", $w['mecz'], $w['blad']);
        continue;
    }
    $druz = implode(' – ', array_slice($w['druzyny'], 0, 2)) . (count($w['druzyny']) > 2 ? ' (+' . (count($w['druzyny']) - 2) . ')' : '');
    $an = [];
    foreach ($w['anomalie'] as $typ => $n) {
        $an[] = $typ . ($n > 1 ? " ×{$n}" : '');
    }
    printf("%-12s  %-52s  %-6s  %-5d  %-9d  %-4d  %-14s  %-13d  %s\n",
        $w['mecz'], mb_strimwidth($druz, 0, 52, '…'), 'P' . ($w['profil'] ?? '?'), $w['wgran'],
        $w['zdarzenia'], $w['tagi'], $w['niezmienniki'], $w['nierozpoznane'], $an === [] ? '—' : implode(', ', $an));
}
exit($zle === 0 ? 0 : 1);
