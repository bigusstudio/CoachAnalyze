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
 *   --przywroc N       nowa wersja = kopia wersji N (created_by NULL, bez
 *                      unieważniania raportów). Działa od razu, bez --zapisz —
 *                      to wyjście awaryjne, np. cofnięcie Pogoni z v6 do v5:
 *                      `--club 2 --przywroc 5`. Raporty wygenerowane na złej
 *                      wersji trzeba potem przeliczyć (`regeneruj_raporty.php`).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO ROBI — w tej kolejności (logika: `app/src/NaprawaTemplatu.php`):
 *
 *   1. zmienna o nazwie aliasu silnika (`SBZ PODAJĄCY`) → alias zmiennej
 *      głównej (`ZDOBYCIE SBZ`),
 *   2. duplikaty po normalizacji („Inne" / „INNE") → alias zmiennej ŻYWEJ
 *      (nazwa w katalogu tagów), potem z kanonem, potem najstarszej. Dwie żywe
 *      nie są scalane — idą do wykazu „do decyzji",
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
 * KONTROLA CIĄGŁOŚCI PRZED ZAPISEM (po regresji v6 Pogoni, raport 28): każdy
 * kanon sprzed naprawy jest po niej, a każda żywa zmienna zostaje zmienną z tym
 * samym kanonem. Naruszenie = PRZERWANIE BEZ ZAPISU, kod wyjścia 1.
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
$przywroc = null;

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
    } elseif ($a === '--przywroc') {
        $przywroc = isset($argumenty[$i + 1]) ? (int) $argumenty[++$i] : 0;
    } elseif (preg_match('/^--przywroc=(\d+)$/', $a, $m) === 1) {
        $przywroc = (int) $m[1];
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

// ─────────────────────────────────────────────────────────── przywrócenie
if ($przywroc !== null) {
    if ($wszystkie || $clubId === null || $clubId <= 0 || $przywroc <= 0) {
        fwrite(STDERR, "--przywroc wymaga jednego klubu (--club ID) i numeru wersji.\n");
        exit(2);
    }
    $zrodlo = ReportTemplates::version((int) $clubId, $przywroc);
    if ($zrodlo === null) {
        fwrite(STDERR, "Klub {$clubId} nie ma wersji {$przywroc}.\n");
        exit(1);
    }
    $config = ReportTemplates::decodeConfig($zrodlo['config']);
    // Notka wersji źródłowej nie jedzie z kopią — nowa wersja dostaje własną.
    unset($config[ReportTemplates::KLUCZ_AUTO]);
    $wersja = ReportTemplates::saveNewVersion(
        (int) $clubId, $config, null, 'przywrócenie v' . $przywroc . ' (napraw_auto_etykiety)'
    );
    printf("klub %d: zapisano v%d = kopia v%d (%d zmiennych)\n",
        $clubId, $wersja, $przywroc, count((array) ($config['variables'] ?? [])));
    echo "Raporty wygenerowane na wersjach pomiędzy przelicz: php app/repairs/regeneruj_raporty.php --club {$clubId}\n";
    exit(0);
}

$kluby = $wszystkie
    ? array_map(static fn(array $w): int => (int) $w['club_id'],
        Db::all('SELECT DISTINCT club_id FROM club_report_templates ORDER BY club_id'))
    : [(int) $clubId];

echo $zapisz ? "TRYB ZAPISU\n" : "PODGLĄD (bez --zapisz nic nie zostanie zmienione)\n";

$kodWyjscia = 0;
foreach ($kluby as $klub) {
    // Logika w `NaprawaTemplatu::plan()` — ta sama, której używa przycisk
    // „Usuń martwe" w Ustawieniach klubu (golden layout W4).
    $plan = NaprawaTemplatu::plan($klub, $takzeReczne, $wykazMartwych, $usunMartwe);
    $templat = $plan['templat'];
    if ($templat === null) {
        echo "\nklub {$klub}: brak templatu — pomijam\n";
        continue;
    }
    $zmiennychPrzed = count((array) (ReportTemplates::decodeConfig($templat['config'])['variables'] ?? []));
    echo "\nklub {$klub}: templat v{$templat['version']}, zmiennych {$zmiennychPrzed}\n";

    foreach ($plan['do_decyzji'] as $opis) {
        echo "  ! do decyzji: {$opis}\n";
    }

    if ($wykazMartwych) {
        if ($plan['martwe'] === null) {
            echo "  martwe: katalog tagów klubu jest pusty — brak podstaw do oceny\n";
        } else {
            echo '  martwe (nazwa nie wystąpiła w żadnym imporcie): ' . count($plan['martwe']) . "\n";
            foreach ($plan['martwe'] as $z) {
                printf("    %s  %-5s  %s\n", $z['id'] ?? '?', $z['source']['type'] ?? '?', $z['source']['raw'] ?? '');
            }
        }
    }

    if ($plan['zmiany'] === []) {
        echo "  nic do zrobienia\n";
        continue;
    }
    foreach ($plan['zmiany'] as $opis) {
        echo "  - {$opis}\n";
    }

    if ($plan['ciaglosc'] !== []) {
        echo "  PRZERWANO BEZ ZAPISU — naprawa zmieniłaby liczby:\n";
        foreach ($plan['ciaglosc'] as $blad) {
            echo "    · {$blad}\n";
        }
        $kodWyjscia = 1;
        continue;
    }

    if ($plan['nowy'] === null) {
        echo '  ODRZUCONE przez walidację templatu: ' . implode(', ', $plan['bledy']) . "\n";
        $kodWyjscia = 1;
        continue;
    }
    if (!$zapisz) {
        echo '  (podgląd) zmiennych po naprawie: ' . $plan['zmiennych'] . "\n";
        continue;
    }
    $wersja = NaprawaTemplatu::zapiszPlan($klub, $plan, null);
    echo "  zapisano wersję v{$wersja}, zmiennych " . $plan['zmiennych'] . "\n";
}

exit($kodWyjscia);
