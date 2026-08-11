<?php
declare(strict_types=1);
/** Weryfikacja Etapu 7: publikacja raportów, tokeny, nieodróżnialność 404. */

use CoachAnalyze\Clubs;
use CoachAnalyze\Db;
use CoachAnalyze\Share;
use CoachAnalyze\Stats;
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

$db = '/tmp/ca_7_' . getmypid() . '.sqlite';
@unlink($db);
ca_test_db($db);

// ---------------------------------------------------------------- token
echo "== token ==\n";
$tokeny = [];
for ($i = 0; $i < 500; $i++) { $tokeny[] = Share::generateToken(); }
check('długość 32 znaki (128 bitów w zapisie hex)',
    count(array_filter($tokeny, fn($t) => strlen($t) === 32)) === 500, $tokeny[0]);
check('wyłącznie znaki szesnastkowe', preg_match('/^[0-9a-f]+$/', implode('', $tokeny)) === 1);
check('brak powtórzeń w 500 losowaniach', count(array_unique($tokeny)) === 500);
check('mieści się w kolumnie CHAR(32)', strlen($tokeny[0]) === 32);

$src = (string) file_get_contents($root . '/app/src/Share.php');
check('token z CSPRNG (random_bytes), nie z rand/uniqid',
    str_contains($src, 'random_bytes') && !preg_match('/\b(mt_rand|rand|uniqid)\s*\(/', $src));

// ---------------------------------------------------------------- tworzenie
echo "\n== tworzenie linku ==\n";
Db::run('DELETE FROM share_links');
$clubId = (int) Db::one('SELECT id FROM clubs LIMIT 1')['id'];
$clubKey = (string) Db::one('SELECT club_key FROM clubs WHERE id = :id', ['id' => $clubId])['club_key'];
$reportId = (int) Db::one('SELECT id FROM reports LIMIT 1')['id'];

$token = Share::create($reportId, $clubId, null, 1);
check('link zapisany', Db::one('SELECT id FROM share_links WHERE token = :t', ['t' => $token]) !== null);
check('odnotowany w audit_log',
    (int) Db::one("SELECT COUNT(*) c FROM audit_log WHERE action='share.created'")['c'] === 1);

$token2 = Share::create($reportId, $clubId, null, 1);
check('drugi link nie unieważnia pierwszego',
    Share::isUsable(Share::resolve($clubKey, $token)) && Share::isUsable(Share::resolve($clubKey, $token2)));

// ---------------------------------------------------------------- rozwiązywanie
echo "\n== rozwiązywanie pary (club_key, token) ==\n";
check('poprawna para działa', Share::isUsable(Share::resolve($clubKey, $token)));
check('zły token → brak', Share::resolve($clubKey, str_repeat('a', 32)) === null);
check('zły klucz klubu → brak', Share::resolve('ZZZZZZZZZZ', $token) === null);
check('token z innego klubu nie działa przy tym kluczu',
    Share::resolve('ZZZZZZZZZZ', $token) === null);
check('śmieci w tokenie nie wywracają zapytania', Share::resolve($clubKey, "' OR 1=1 --") === null);
check('śmieci w kluczu nie wywracają zapytania', Share::resolve("' OR 1=1 --", $token) === null);

// ---------------------------------------------------------------- stany
echo "\n== stany linku ==\n";
$wygasly = Share::create($reportId, $clubId, Stats::now('-1 day'), 1);
check('link wygasły nie działa', !Share::isUsable(Share::resolve($clubKey, $wygasly)));

$przyszly = Share::create($reportId, $clubId, Stats::now('+30 days'), 1);
check('link z datą w przyszłości działa', Share::isUsable(Share::resolve($clubKey, $przyszly)));

$doOdwolania = Share::create($reportId, $clubId, null, 1);
$linkId = (int) Db::one('SELECT id FROM share_links WHERE token = :t', ['t' => $doOdwolania])['id'];
check('odwołanie zwraca true', Share::revoke($linkId, 1) === true);
check('odwołany link nie działa', !Share::isUsable(Share::resolve($clubKey, $doOdwolania)));
check('powtórne odwołanie nic nie robi', Share::revoke($linkId, 1) === false);

// ---------------------------------------------------------------- licznik
echo "\n== licznik wejść ==\n";
$link = Share::resolve($clubKey, $token);
Share::registerView((int) $link['id']);
Share::registerView((int) $link['id']);
$po = Db::one('SELECT views, last_viewed_at FROM share_links WHERE id = :id', ['id' => $link['id']]);
check('licznik rośnie', (int) $po['views'] === 2, (string) $po['views']);
check('data ostatniego wejścia zapisana', $po['last_viewed_at'] !== null);

// ---------------------------------------------------------------- klub raportu
echo "\n== klub dla raportu ==\n";
check('klub brany z meczu', Share::clubForReport($reportId) !== null);
Db::run('UPDATE matches SET club_home_id = NULL, club_away_id = NULL WHERE id = (SELECT match_id FROM reports WHERE id = :r)', ['r' => $reportId]);
check('brak klubu → null (nie podstawiamy przypadkowego)', Share::clubForReport($reportId) === null);

// ---------------------------------------------------------------- widok
echo "\n== widok panelu ==\n";
Db::run('UPDATE matches SET club_home_id = :c WHERE id = (SELECT match_id FROM reports WHERE id = :r)',
    ['c' => $clubId, 'r' => $reportId]);
$links = Share::forReport($reportId);
$stany = array_column($links, 'stan');
check('stany rozpoznane', in_array('active', $stany, true) && in_array('expired', $stany, true)
    && in_array('revoked', $stany, true), implode(',', $stany));

$html = View::render('share_list', [
    'report' => Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $reportId]),
    'links' => $links, 'appUrl' => 'https://app.example.pl', 'notice' => null, 'error' => null,
]);
check('pełny adres do skopiowania', str_contains($html, 'https://app.example.pl/r/' . $clubKey . '/'));
check('odwoływanie POST-em z tokenem CSRF',
    str_contains($html, 'name="csrf"') && str_contains($html, '/odwolaj'));
check('odwołany link nie ma przycisku odwołania',
    substr_count($html, 'Odwołaj') < count($links));

@unlink($db);
$out = ob_get_clean(); echo $out;
echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
