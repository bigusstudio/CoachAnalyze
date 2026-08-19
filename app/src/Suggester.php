<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Podpowiadanie pojęcia kanonicznego dla pozycji słownika.
 *
 * ISTNIEJE JAKO INTERFEJS, CHOĆ MA JEDNĄ IMPLEMENTACJĘ — i to jest świadome.
 * Podpowiedzi liczy dziś heurystyka (odległość edycyjna wobec tagów już
 * zmapowanych plus człon nazwy pojęcia). Model językowy jest w backlogu
 * przebudowy, po Sesji 7, a pierwszym kandydatem jest właśnie import
 * założycielski nowego słownika — czyli miejsce, w którym heurystyka jest
 * najsłabsza, bo nie ma jeszcze CZEGO porównywać.
 *
 * Szew stoi tutaj, a nie w konfiguratorze, żeby podmiana źródła podpowiedzi
 * nie wymagała dotykania ekranów ani walidacji.
 *
 * KONTRAKT JEST NIENARUSZALNY NIEZALEŻNIE OD ŹRÓDŁA:
 * podpowiedź jest PROPOZYCJĄ, którą zatwierdza człowiek. Nic się nie zapisuje
 * bez potwierdzenia. Automatyczne przypisanie pojęcia zmienia liczby
 * w raporcie — dokładnie ta klasa błędu, dla której istnieje test złoty.
 */
interface Suggester
{
    /** Pozycja słownika jest tagiem (mapuje się na pojęcie kanoniczne). */
    public const TAG = 'tag';

    /** Pozycja słownika jest etykietą (mapuje się na kwalifikator). */
    public const ETYKIETA = 'label';

    /**
     * Poziomy pewności. Interfejs mówi UI, ile zaufania podpowiedź zasługuje —
     * bez tego ekran przedstawia zgadywankę tak samo jak trafienie pewne,
     * a operator uczy się klikać „dalej" bez czytania.
     */
    public const PEWNA        = 'pewna';
    public const PRAWDOPODOBNA = 'prawdopodobna';
    public const ZGADYWANA    = 'zgadywana';
    public const BRAK         = 'brak';

    /**
     * @param string $typ  self::TAG albo self::ETYKIETA
     * @param string $raw  nazwa z eksportu
     * @param array<string,string> $znane  decyzje klubu: nazwa => pojęcie
     * @return array{canon:?string, confidence:string, reason:?string, score:float}
     */
    public function suggest(string $typ, string $raw, array $znane): array;

    /** Nazwa źródła — trafia do raportu pokrycia, żeby było wiadomo, kto proponował. */
    public function name(): string;
}
