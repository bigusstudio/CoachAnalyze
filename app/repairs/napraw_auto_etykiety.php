<?php
declare(strict_types=1);

/**
 * Porządki w templatach klubów po imporcie bez tarcia (silnik 0.16.3).
 *
 *   php app/repairs/napraw_auto_etykiety.php --club 2                 # podgląd
 *   php app/repairs/napraw_auto_etykiety.php --club 2 --zapisz
 *   php app/repairs/napraw_auto_etykiety.php --all --zapisz
 *
 * Opcje:
 *   --takze-reczne     poprawiaj etykiety także zmiennych z wersji RĘCZNYCH
 *                      (domyślnie wyłącznie zmienne z wersji z notką auto)
 *   --wykaz-martwych   wypisz zmienne, których nazwa nie wystąpiła w żadnym
 *                      imporcie klubu (`tag_catalog`) — do decyzji człowieka
 *   --usun-martwe      usuń je z templatu (wymaga --zapisz, żeby zadziałało)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO ROBI — w tej kolejności (logika: `app/src/NaprawaTemplatu.php`):
 *
 *   1. zmienna o nazwie aliasu silnika (`SBZ PODAJĄCY`) → alias zmiennej
 *      głównej (`ZDOBYCIE SBZ`),
 *   2. duplikaty po normalizacji („Inne" / „INNE") → alias najstarszej,
 *   3. etykieta w wielkości tytułowej („Zdobycie Sbz"), której nikt nie
 *      zmieniał → surowa nazwa („ZDOBYCIE SBZ"),
 *   4. opcjonalnie: wykaz / usunięcie zmiennych martwych.
 *
 * PUNKT 3 DOMYŚLNIE DOTYCZY TYLKO WERSJI AUTO — tak brzmiało zamówienie. Odbiór
 * na Pogoni pokazał jednak, że wielkość tytułowa przyszła z wersji 3, RĘCZNEJ
 * (domyślna wartość formularza ekranu różnic), więc bez `--takze-reczne`
 * skrypt tych etykiet nie ruszy. Etykiety zmienionej przez człowieka nie
 * rusza nigdy: poprawiamy wyłącznie tekst równy dawnej propozycji maszyny.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ZAPIS = NOWA WERSJA TEMPLATU z notką (`NaprawaTemplatu::NOTKA`), jak wersja
 * automatyczna — nie unieważnia raportów, bo żadna operacja nie zmienia liczb.
 * Bez `--zapisz` skrypt tylko pokazuje, co by zrobił (app/repairs/README.md,
 * „podgląd przed zmianą").
 *
 * POWTÓRZENIE JEST BEZPIECZNE: po naprawie nie ma czego scalać ani poprawiać,
 * więc drugi przebieg nie tworzy wersji.
 *
 * Zrzut bazy przed `--zapisz` — app/repairs/README.md, zasada 4.
 */

$root = dirname(__DIR__, 2);
require $root . '/app/src/bootstrap.php';

use CoachAnalyze\Db;
use CoachAnalyze\NaprawaTemplatu;
use CoachAnalyze\ReportTemplates;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// ─────────────────────────────────────────────────────────────── argumenty
$clubId = null;
$wszystkie = false;
$zapisz = false;
$takzeReczne = false;
$wykazMartwych = false;
$usunMartwe = false;

$argumenty = $argv ?? [];
for ($i = 1; $i < count($argumenty); $i++) {
    $a = (string) $argumenty[$i];
    if ($a === '--all') {
        $wszystkie = true;
    } elseif ($a === '--zapisz') {
        $zapisz = true;
    } elseif ($a === '--takze-reczne') {
        $takzeReczne = true;
    } elseif ($a === '--wykaz-martwych') {
        $wykazMartwych = true;
    } elseif ($a === '--usun-martwe') {
        $usunMartwe = $wykazMartwych = true;
    } elseif ($a === '--club') {
        $clubId = isset($argumenty[$i + 1]) ? (int) $argumenty[++$i] : 0;
    } elseif (preg_match('/^--club=(\d+)$/', $a, $m) === 1) {
        $clubId = (int) $m[1];
    } else {
        fwrite(STDERR, "Nieznany argument: {$a}\n");
        exit(2);
    }
}

if (($clubId === null || $clubId <= 0) && !$wszystkie) {
    fwrite(STDERR, "Podaj --club ID albo --all.\n");
    exit(2);
}

$kluby = $wszystkie
    ? array_map(static fn(array $w): int => (int) $w['club_id'],
        Db::all('SELECT DISTINCT club_id FROM club_report_templates ORDER BY club_id'))
    : [(int) $clubId];

echo $zapisz ? "TRYB ZAPISU\n" : "PODGLĄD (bez --zapisz nic nie zostanie zmienione)\n";

$kodWyjscia = 0;
foreach ($kluby as $klub) {
    $templat = ReportTemplates::current($klub);
    if ($templat === null) {
        echo "\nklub {$klub}: brak templatu — pomijam\n";
        continue;
    }
    $config = ReportTemplates::decodeConfig($templat['config']);
    $zmienne = array_values((array) ($config['variables'] ?? []));
    echo "\nklub {$klub}: templat v{$templat['version']}, zmiennych " . count($zmienne) . "\n";

    [$zmienne, $z1] = NaprawaTemplatu::scalAliasySilnika($zmienne);
    [$zmienne, $z2] = NaprawaTemplatu::scalDuplikaty($zmienne);
    [$zmienne, $z3] = NaprawaTemplatu::poprawEtykiety(
        $zmienne, NaprawaTemplatu::zWersjiAuto($klub), $takzeReczne
    );
    $zmiany = array_merge($z1, $z2, $z3);

    if ($wykazMartwych) {
        $martwe = NaprawaTemplatu::martwe($klub, $zmienne);
        if ($martwe === null) {
            echo "  martwe: katalog tagów klubu jest pusty — brak podstaw do oceny\n";
        } else {
            echo '  martwe (nazwa nie wystąpiła w żadnym imporcie): ' . count($martwe) . "\n";
            foreach ($martwe as $i) {
                $z = $zmienne[$i];
                printf("    %s  %-5s  %s\n", $z['id'] ?? '?', $z['source']['type'] ?? '?', $z['source']['raw'] ?? '');
            }
            if ($usunMartwe && $martwe !== []) {
                foreach ($martwe as $i) {
                    $zmiany[] = sprintf('usunięto martwą %s „%s"', $zmienne[$i]['id'] ?? '?', $zmienne[$i]['source']['raw'] ?? '');
                    unset($zmienne[$i]);
                }
                $zmienne = array_values($zmienne);
            }
        }
    }

    if ($zmiany === []) {
        echo "  nic do zrobienia\n";
        continue;
    }
    foreach ($zmiany as $opis) {
        echo "  - {$opis}\n";
    }

    [$nowy, $bledy] = NaprawaTemplatu::nowyConfig($config, $zmienne);
    if ($nowy === null) {
        echo '  ODRZUCONE przez walidację templatu: ' . implode(', ', $bledy) . "\n";
        $kodWyjscia = 1;
        continue;
    }
    if (!$zapisz) {
        echo '  (podgląd) zmiennych po naprawie: ' . count($zmienne) . "\n";
        continue;
    }
    $wersja = ReportTemplates::saveNewVersion($klub, $nowy, null, NaprawaTemplatu::NOTKA);
    echo "  zapisano wersję v{$wersja}, zmiennych " . count($zmienne) . "\n";
}

exit($kodWyjscia);
