<?php
declare(strict_types=1);
/** Weryfikacja Etapu 4b: club_key, barwy przez silnik, herby, aliasy. */

use CoachAnalyze\Clubs;
use CoachAnalyze\Config;
use CoachAnalyze\Crest;
use CoachAnalyze\Db;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
ob_start();
require $root . '/app/src/bootstrap.php';
require __DIR__ . '/seed.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  BŁĄD $name" . ($detail ? " — $detail" : '') . "\n"; }
}

Config::reset([
    'APP_ENV' => 'test',
    'PYTHON_BIN' => __DIR__ . '/pybin',
    'STORAGE_PATH' => '/tmp/ca4b_storage',
    'INSPECT_TIMEOUT' => '60',
]);
@mkdir('/tmp/ca4b_storage', 0770, true);
$db = '/tmp/ca4b.sqlite';
@unlink($db);
ca_test_db($db, false);

// ---------------------------------------------------------------- club_key
echo "== club_key ==\n";
$klucze = [];
for ($i = 0; $i < 200; $i++) {
    $k = Clubs::generateKey();
    $klucze[] = $k;
}
check('długość 8–10 znaków', count(array_filter($klucze, fn($k) => strlen($k) >= 8 && strlen($k) <= 10)) === 200,
    'przykład: ' . $klucze[0]);
check('base32 bez znaków mylących (0 O 1 I L U)',
    preg_match('/[01OILU]/', implode('', $klucze)) !== 1);
check('tylko dozwolony alfabet', preg_match('/^[2-9A-HJKMNP-TV-Z]+$/', implode('', $klucze)) === 1);
check('brak powtórzeń w 200 losowaniach', count(array_unique($klucze)) === 200);

// ---------------------------------------------------------------- barwy
echo "\n== barwy: PHP sprawdza format, silnik liczy kontrast ==\n";

// Warstwa zadan NIE MOZE juz wolac silnika (disable_functions na PHP-FPM),
// wiec `Engine::contrastColor()` przestalo istniec. PHP przyjmuje barwe
// i sprawdza jej format; korekta kontrastu jedzie do silnika w
// `options.contrast_fix` i liczy sie tam, gdzie zawsze byla arytmetyka koloru.
check('poprawna barwa przechodzi bez zmian', View::color('#E8722C') === '#E8722C');
check('smieci zastapione wartoscia domyslna', View::color('nie-kolor', '#E8722C') === '#E8722C');
check('zapis bez # odrzucony jako niepelny', View::color('E8722C', '#123456') === '#123456');
check('skrocony zapis odrzucony', View::color('#FFF', '#123456') === '#123456');

// Regula z CLAUDE.md: arytmetyka koloru nie wraca do PHP tylnymi drzwiami.
$zrodlaPhp = '';
foreach ([$root . '/app/src/View.php', $root . '/app/src/Clubs.php'] as $plik) {
    $zrodlaPhp .= (string) file_get_contents($plik);
}
check('PHP nie liczy luminancji ani nie miesza kanalow',
    !preg_match('/hexdec|0\.2126|0\.7152|luminan/i', $zrodlaPhp));

check('korekta zamawiana u silnika przez options.contrast_fix',
    str_contains((string) file_get_contents($root . '/app/bin/run_job.php'), 'contrast_fix'));

// ---------------------------------------------------------------- CRUD
echo "\n== CRUD klubów ==\n";
$id = Clubs::create(1, [
    'name' => 'Klub A', 'short_name' => 'HUT',
    'color_primary' => '#E8722C', 'color_secondary' => '#2C6FE8',
    'is_own_team' => true, 'aliases' => "KLUB A\nKA",
]);
$club = Clubs::find($id);
check('klub zapisany', $club !== null && $club['name'] === 'Klub A');
check('club_key nadany przy tworzeniu', strlen((string) $club['club_key']) === 10);
check('aliasy zapisane', Clubs::decodeAliases($club['aliases_json']) === ['KLUB A', 'KA']);

$kluczPrzed = $club['club_key'];
Clubs::update($id, 1, [
    'name' => 'Klub A S.A.', 'short_name' => 'HUT',
    'color_primary' => '#C25A16', 'color_secondary' => null,
    'is_own_team' => true, 'aliases' => "KLUB A",
]);
check('club_key NIEZMIENNY po edycji', Clubs::find($id)['club_key'] === $kluczPrzed);
check('nazwa zaktualizowana', Clubs::find($id)['name'] === 'Klub A S.A.');

// ---------------------------------------------------------------- aliasy
echo "\n== dopasowanie po nazwie z eksportu ==\n";
check('dopasowanie po aliasie', (Clubs::matchByExportName('KLUB A')['id'] ?? null) === $id);
check('dopasowanie mimo wielkości liter', (Clubs::matchByExportName('klub a')['id'] ?? null) === $id);
check('dopasowanie mimo nadmiarowych spacji', (Clubs::matchByExportName('   KLUB    A  ')['id'] ?? null) === $id);
check('obcy klub bez dopasowania', Clubs::matchByExportName('WISŁA') === null);

Clubs::rememberAlias($id, 'KLUB A KRAKOW SA');
check('nowy alias zapamiętany', in_array('KLUB A KRAKOW SA', Clubs::decodeAliases(Clubs::find($id)['aliases_json']), true));
Clubs::rememberAlias($id, 'KLUB A KRAKOW SA');
check('alias nie duplikuje się', count(Clubs::decodeAliases(Clubs::find($id)['aliases_json'])) === 2);

// ---------------------------------------------------------------- konfiguracja dla silnika
echo "\n== konfiguracja przekazywana silnikowi ==\n";
$id2 = Clubs::create(1, ['name' => 'Klub B', 'short_name' => 'POG',
    'color_primary' => '#2C6FE8', 'color_secondary' => null, 'is_own_team' => false, 'aliases' => '']);
$cfg = Clubs::engineConfig($id, $id2);
check('strona us i them', array_keys($cfg) === ['us', 'them']);
check('nazwa i barwa w konfiguracji', $cfg['us']['name'] === 'Klub A S.A.' && $cfg['us']['color'] === '#C25A16');
check('brak klubu = brak wpisu (nie zmyślamy)', Clubs::engineConfig(null, null) === []);

// ---------------------------------------------------------------- usuwanie
echo "\n== usuwanie ==\n";
Db::run('INSERT INTO matches (owner_id, club_home_id, status) VALUES (1, ?, ?)', [$id, 'done']);
$r = Clubs::delete($id, 1);
check('klub użyty w meczu nie da się usunąć', $r['ok'] === false && $r['error'] === 'club.err.in_use');
$r2 = Clubs::delete($id2, 1);
check('nieużywany klub usunięty', $r2['ok'] === true && Clubs::find($id2) === null);

// ---------------------------------------------------------------- herby
echo "\n== herby ==\n";
$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 200);
$svgOk = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>';
$svgZly = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
$svgOnload = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="5"/></svg>';
$svgExt = '<svg xmlns="http://www.w3.org/2000/svg"><image xlink:href="http://zly.example/x.png"/></svg>';

$refl = new ReflectionClass(Crest::class);
$sniff = $refl->getMethod('sniff'); $sniff->setAccessible(true);
$safe = $refl->getMethod('svgLooksSafe'); $safe->setAccessible(true);

foreach ([['png', $png, 'png'], ['svg', $svgOk, 'svg'], ['tekst', 'zwykły tekst', null]] as [$opis, $tresc, $oczek]) {
    $p = tempnam(sys_get_temp_dir(), 'crest'); file_put_contents($p, $tresc);
    check("rozpoznanie zawartości: $opis", $sniff->invoke(null, $p) === $oczek,
        'wykryto: ' . var_export($sniff->invoke(null, $p), true));
    @unlink($p);
}

foreach ([['czysty svg', $svgOk, true], ['ze <script>', $svgZly, false],
          ['z onload=', $svgOnload, false], ['z odwołaniem http', $svgExt, false]] as [$opis, $tresc, $oczek]) {
    $p = tempnam(sys_get_temp_dir(), 'crest'); file_put_contents($p, $tresc);
    check("svg $opis " . ($oczek ? 'przyjęty' : 'odrzucony'), $safe->invoke(null, $p) === $oczek);
    @unlink($p);
}

check('typ MIME svg', Crest::mime('/x/y.svg') === 'image/svg+xml');
check('typ MIME png', Crest::mime('/x/y.png') === 'image/png');
check('limit 2 MB', Crest::MAX_BYTES === 2097152);

// ---------------------------------------------------------------- widok
echo "\n== widoki ==\n";
$html = View::render('clubs_list', ['clubs' => Clubs::all(), 'notice' => null, 'error' => null]);
check('klucz klubu widoczny na liście', str_contains($html, (string) $kluczPrzed));
check('barwa przez zmienną CSS, nie w treści reguły', str_contains($html, '--probka:'));
check('herb przez <img>, nie wklejony XML', !str_contains($html, '<svg'));

check('barwa spoza wzorca zamieniona na zastępczą', View::color('red; background:url(x)') === '#888888');
check('poprawna barwa przechodzi', View::color('#AABBCC') === '#AABBCC');

$pusty = View::render('clubs_list', ['clubs' => [], 'notice' => null, 'error' => null]);
check('pusty stan opisowy', str_contains($pusty, 'Brak klubów'));

$form = View::render('club_form', ['club' => null, 'suggestedName' => 'KLUB B-SOKÓŁ LUBACZÓW',
    'error' => null, 'notice' => null, 'backTo' => '/import/1']);
check('nazwa z eksportu podpowiedziana w formularzu', str_contains($form, 'KLUB B-SOKÓŁ LUBACZÓW'));
check('formularz bez JavaScriptu', !str_contains($form, 'onsubmit') && !str_contains($form, '<script'));

@unlink($db);
$out = ob_get_clean(); echo $out;
echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
