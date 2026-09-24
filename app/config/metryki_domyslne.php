<?php
declare(strict_types=1);

/**
 * Definicje metryk domyślnych (sesja 3 pivotu „viewer").
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TO JEST PLIK PRZEJŚCIOWY I MA ZNIKNĄĆ W SESJI 5.
 *
 * Docelowo definicje mieszkają w templacie raportu klubu, bo każdy klub nazywa
 * zdarzenia po swojemu i metodyka jest częścią jego templatu, nie kodu. Dziś
 * templatu nie czyta warstwa żądań — dlatego plik.
 *
 * Do tego czasu obowiązuje tu ta sama zasada, co w całym pivocie: liczymy po
 * SUROWYCH NAZWACH TAGÓW, tych, które analityk wpisał w LiveTag. Żadnych pojęć
 * kanonicznych — warstwa kanoniczna jest uśpiona (docs/STAN_PIVOTU.md §2.1),
 * a raport liczy dokładnie tak samo (`e.tag==='STRZAŁ'` w szablonie).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KSZTAŁT DEFINICJI
 *
 *   'label'  — nazwa dla człowieka (docelowo z `pl.php`, tu wprost)
 *   'agg'    — count | sum_xg | ratio | avg_per_match
 *   'filter' — { tags[], has_label[], no_label[], team_side[], half, minute_from/to }
 *   'of'     — mianownik, WYŁĄCZNIE dla `ratio`
 *
 * ALIASY TAGÓW SĄ LISTĄ W `tags`. `ZDOBYCIE SBZ` i `SBZ PODAJĄCY` to dwie nazwy
 * tego samego zdarzenia w różnych wersjach tagowania klienta — liczą się RAZEM.
 * Klub, który używa tylko jednej, ma pełne pokrycie; sprawdza to
 * `Metrics::brakujaceTagi()`.
 *
 * PERSPEKTYWA. `team_side` przyjmuje `us`, `them` i `none`.
 *
 * `none` to zdarzenia bez wypełnionej kolumny drużyny (pułapka 5 — w eksporcie
 * referencyjnym 196 z 294). Zgodnie z regułą z `docs/STAN_PIVOTU.md` liczą się
 * DLA TENANTA: analityk klubu taguje własne straty i odbiory, nie cudze, więc
 * pominięcie ich zabrałoby większość danych o pressingu i reakcji na stratę.
 * Tam, gdzie zdarzenie MA drużynę (strzały, wejścia w SBZ), `none` nie wchodzi —
 * inaczej doliczylibyśmy do klubu strzały rywala bez przypisania.
 */

return [

    // ── liczby, które raport pokazuje w Przeglądzie ──────────────────────

    'strzaly' => [
        'label'  => 'Strzały',
        'agg'    => 'count',
        'filter' => ['tags' => ['STRZAŁ'], 'team_side' => ['us']],
    ],

    'xg' => [
        'label'  => 'xG',
        'agg'    => 'sum_xg',
        // Bez filtra tagu: xG niesie ten wiersz, któremu analityk je wpisał.
        // Filtrowanie po `STRZAŁ` gubiłoby xG zapisane przy innym tagu,
        // a silnik i tak przepuszcza wyłącznie wartości wyglądające na xG.
        'filter' => ['team_side' => ['us']],
    ],

    'sbz' => [
        'label'  => 'Zdobycie SBZ',
        'agg'    => 'count',
        'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']],
    ],

    'sbz_na_mecz' => [
        'label'  => 'SBZ na mecz',
        'agg'    => 'avg_per_match',
        'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']],
    ],

    // ── wskaźniki udziału ────────────────────────────────────────────────

    'sbz_ze_strzalem' => [
        'label'  => 'Wejścia w SBZ zakończone strzałem',
        'agg'    => 'ratio',
        'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'],
                     'has_label' => ['STRZAŁ'], 'team_side' => ['us']],
        'of'     => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']],
    ],

    'pressing' => [
        'label'  => 'Pressing skuteczny',
        'agg'    => 'ratio',
        // Zdarzenia pressingu bywają tagowane bez drużyny — stąd `none`
        // po obu stronach ułamka. Gdyby był tylko w liczniku, wskaźnik
        // przekraczałby 100% na pierwszym meczu z niepełnym tagowaniem.
        'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'],
                     'has_label' => ['PRESSING'], 'team_side' => ['us', 'none']],
        'of'     => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us', 'none']],
    ],

    'reakcja_na_strate' => [
        'label'  => 'Reakcja na stratę',
        'agg'    => 'ratio',
        'filter' => ['tags' => ['STRATA'], 'has_label' => ['REAKCJA'],
                     'team_side' => ['us', 'none']],
        'of'     => ['tags' => ['STRATA'], 'team_side' => ['us', 'none']],
    ],

    'duele_def_wygrane' => [
        'label'  => '1x1 w defensywie — wygrane',
        'agg'    => 'ratio',
        'filter' => ['tags' => ['1x1 DEF.', '1x1 DEF'], 'has_label' => ['WYGRANY'],
                     'team_side' => ['us', 'none']],
        'of'     => ['tags' => ['1x1 DEF.', '1x1 DEF'], 'team_side' => ['us', 'none']],
    ],

    'iii_strefa_udane' => [
        'label'  => 'III strefa — udane',
        'agg'    => 'ratio',
        'filter' => ['tags' => ['III STREFA', 'III STREFA PODAJĄCY/OTRZYMUJĄCY'],
                     'has_label' => ['UDANA'], 'team_side' => ['us']],
        'of'     => ['tags' => ['III STREFA', 'III STREFA PODAJĄCY/OTRZYMUJĄCY'],
                     'team_side' => ['us']],
    ],
];
