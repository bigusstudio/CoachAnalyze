<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Podpowiedzi z heurystyki — jedyna implementacja `Suggester` na dziś.
 *
 * Opakowuje `Mappings::suggest()`, czyli mechanizm istniejący i sprawdzony
 * w kreatorze mapowań. Nie powiela jego logiki: całe rozstrzyganie zostaje
 * tam, gdzie było, a ta klasa tłumaczy wynik liczbowy na POZIOM PEWNOŚCI,
 * którego potrzebuje interfejs konfiguratora.
 *
 * PROGI SĄ TU, NIE W WIDOKU. Widok pyta o poziom i rysuje znacznik; gdyby
 * porównywał liczby sam, każdy kolejny ekran robiłby to po swojemu i „pewne"
 * znaczyłoby co innego w dwóch miejscach.
 */
final class HeuristicSuggester implements Suggester
{
    /**
     * Bliskość niemal identyczna — literówka, kropka, spacja („1x1 DEF." przy
     * „1x1 DEF"). Tu podpowiedź jest tak dobra, jak dane wejściowe.
     */
    private const PROG_PEWNEJ = 0.92;

    /**
     * Dolny próg `Mappings::suggest()`. Poniżej niego heurystyka nie zwraca
     * już dopasowania po podobieństwie — zostaje ewentualnie człon nazwy.
     */
    private const PROG_PRAWDOPODOBNEJ = 0.82;

    public function name(): string
    {
        return 'heurystyka';
    }

    /**
     * @param array<string,string> $znane
     * @return array{canon:?string, confidence:string, reason:?string, score:float}
     */
    public function suggest(string $typ, string $raw, array $znane): array
    {
        $wynik = Mappings::suggest($raw, $znane);

        $canon = $wynik['concept'] ?? null;
        $score = (float) ($wynik['score'] ?? 0.0);
        $zrodlo = $wynik['source'] ?? null;

        /*
         * ETYKIETA MAPUJE SIĘ NA KWALIFIKATOR, NIE NA POJĘCIE.
         *
         * `Mappings::suggest()` jest napisane dla tagów i zwraca pojęcia.
         * Gdyby jego wynik przeszedł tu bez sprawdzenia, etykieta dostałaby
         * podpowiedź `shot` — czyli wartość, której walidacja templatu i tak
         * nie przyjmie, a operator zobaczyłby propozycję nie do zatwierdzenia.
         * Odrzucamy ją tutaj, zamiast pozwolić jej dojść do ekranu.
         */
        if ($typ === self::ETYKIETA && $canon !== null
            && !in_array($canon, Mappings::KWALIFIKATORY, true)) {
            $canon = null;
            $score = 0.0;
            $zrodlo = null;
        }

        if ($canon === null) {
            return [
                'canon' => null,
                'confidence' => self::BRAK,
                'reason' => null,
                'score' => 0.0,
            ];
        }

        return [
            'canon'      => $canon,
            'confidence' => $this->poziom($score, (string) $zrodlo),
            'reason'     => $wynik['reason'] ?? null,
            'score'      => $score,
        ];
    }

    /**
     * Wynik liczbowy na poziom pewności.
     *
     * Dopasowanie po CZŁONIE NAZWY jest zawsze „zgadywane", niezależnie od
     * wartości: „SBZ PODAJĄCY" trafia w `entry_sbz` przez jedno słowo i bywa
     * trafne, ale nie jest zmierzone. Traktowanie go na równi z bliskością
     * całej nazwy uczyłoby operatora ufać obu tak samo.
     */
    private function poziom(float $score, string $zrodlo): string
    {
        if ($zrodlo === 'keyword') {
            return self::ZGADYWANA;
        }
        if ($score >= self::PROG_PEWNEJ) {
            return self::PEWNA;
        }
        if ($score >= self::PROG_PRAWDOPODOBNEJ) {
            return self::PRAWDOPODOBNA;
        }
        return self::ZGADYWANA;
    }
}
