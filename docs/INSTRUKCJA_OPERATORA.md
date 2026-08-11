# Instrukcja operatora

Dla osoby, która wgrywa eksporty i wysyła raporty. Bez wiedzy technicznej.

Panel: **https://app.coachanalyze.pl** · logowanie adresem e-mail i hasłem.

---

## 1. Wgranie eksportu z LiveTag.Pro

1. **Mecze → Wgraj eksport** (albo przycisk na Pulpicie).
2. Wybierz plik **CSV** — to tabela zdarzeń, wymagana.
3. Opcjonalnie dołóż plik **JSON** projektu LiveTag. Bez niego oś czasu raportu
   użyje barw klubu zamiast oryginalnej palety z tablicy kodowej.
4. **Wgraj i sprawdź pokrycie.**

Panel od razu pokazuje **raport pokrycia** — czyli to, co silnik znalazł w pliku.
Raport jeszcze nie powstaje.

> Jeśli przycisk jest nieaktywny i widać czerwony komunikat o katalogu na pliki —
> to problem serwera, nie Twój. Zgłoś go wykonawcy.

---

## 2. Raport pokrycia — co znaczą liczby i ostrzeżenia

To jedyny moment, w którym łatwo wychwycić błąd w tagowaniu. Warto tu spojrzeć.

| Liczba | Co oznacza |
|---|---|
| **Zdarzenia** | Wszystkie wiersze eksportu. Powinny odpowiadać temu, co wytagowano |
| **Strzały / Strzały z xG** | Jeśli xG jest dużo mniej niż strzałów, część nie ma wpisanej wartości w komentarzu |
| **Zdobycia SBZ z wektorem** | Bez wektora nie będzie strzałek na mapie |
| **III strefa ze współrzędnymi** | **Zero przy niezerowej liczbie wejść oznacza, że sekcja III strefy będzie wyłączona** |
| **Zdarzenia bez przypisanej drużyny** | Normalne i częste — trafiają do osobnej sekcji raportu |
| **Zdarzenia z zawodnikiem** | Zero oznacza brak warstwy indywidualnej. LiveTag często nie wypełnia tej kolumny |

### Ostrzeżenia

| Ostrzeżenie | Co robić |
|---|---|
| `MASZA POŁOWA` | Literówka w tagowaniu. Silnik mapuje ją na `NASZA POŁOWA`, liczby są poprawne. Warto poprawić w LiveTag na przyszłość |
| `Ujemny czas startu taga` | Bufor nagrania przed zdarzeniem. Przycięty do zera, nic nie tracisz |
| `Kolumna zawodnika jest pusta` | Brak warstwy indywidualnej. Nic nie zrobisz po stronie panelu |
| `Brak pliku projektu LiveTag` | Dołącz plik JSON, jeśli chcesz oryginalne barwy tablicy kodowej |
| `Tagi bez mapowania` | Silnik nie zna tego taga i **nie liczy go w metrykach**. Zdarzenia nie znikają. Zgłoś nazwę wykonawcy, żeby dodał regułę |
| `Liczba w komentarzu przy tagu, który nie jest strzałem` | Komentarz z liczbą przy niestrzale. Wartość pominięta przy xG — to zamierzone |

### Sekcje niedostępne

Każda niedostępna sekcja ma podany **powód**. Najczęstszy: eksport nie zawiera
pozycji III strefy. Raport powstanie bez tej sekcji — to nie jest błąd.

### Drużyny wykryte w danych

Panel pokazuje nazwy znalezione w pliku. Jeśli klub jest już założony, zobaczysz
**dopasowany**. Jeśli nie — kliknij **Załóż klub z tą nazwą**, popraw nazwę na
poprawną i zapisz. Przy kolejnych meczach ten klub rozpozna się sam.

---

## 3. Wygenerowanie raportu

Na ekranie pokrycia kliknij **Generuj raport**. Zadanie trafia do kolejki i rusza
od razu; strona statusu odświeża się sama. Raport bywa gotowy po kilkudziesięciu sekundach.

Gdy status zmieni się na **gotowe**, pojawi się **Otwórz raport**.

**Generuj ponownie** tworzy raport z tego samego pliku jeszcze raz — przydaje się
po dodaniu klubu albo poprawieniu barw. Nie trzeba wgrywać eksportu od nowa.

---

## 4. Udostępnienie raportu

1. Przy raporcie kliknij **Utwórz link**.
2. Opcjonalnie ustaw **datę wygaśnięcia**. Puste pole = link bezterminowy.
3. Skopiuj adres z kolumny **Adres** i wyślij.

Adres wygląda tak: `https://app.coachanalyze.pl/r/HUT7K2QX/a1b2c3…`

- Odbiorca **nie potrzebuje konta ani hasła** — wystarczy link.
- Raport nie trafia do wyszukiwarek.
- Panel pokazuje **liczbę wejść** i **datę ostatniego wejścia**.
- **Odwołaj** unieważnia link natychmiast. Możesz wtedy utworzyć nowy — stary
  przestaje działać, nowy działa od razu.

> Klucz klubu w adresie jest stały. Odwołanie linku nie zmienia go i nie unieważnia
> pozostałych linków tego klubu.

Wszystkie aktywne linki widać w **Linki**.

---

## 5. Gdy zadanie padnie

Pulpit pokazuje sekcję **Wymaga uwagi** oraz listę zadań ze stanem **błąd**.

1. Otwórz zadanie (numer w panelu „Zadania").
2. Przeczytaj **Treść błędu** — to jedno–dwa zdania po polsku.
3. Zdecyduj:

| Treść błędu | Co robić |
|---|---|
| `Ten plik nie wygląda na eksport z LiveTag.Pro` | Zły plik. Wgraj właściwy CSV |
| `Brak wymaganych kolumn` | Eksport niepełny. Wyeksportuj z LiveTag ponownie |
| `Przekroczony limit czasu` | Kliknij **Ponów** |
| `Silnik zakończył się kodem 4` | Kliknij **Ponów** raz. Jeśli powtórzy się — zgłoś wykonawcy, pełny ślad jest w logu serwera |

**Ponów** wraca zadanie do kolejki i zeruje licznik prób. Zadania w stanie **w toku**
nie da się ponowić — jeśli wisi dłużej niż 5 minut, nadzorca zwolni je automatycznie
i wtedy pojawi się przycisk.

---

## 6. Notatki

**Notatki** pozwalają zapisać uwagi na trzech poziomach:

- **mecz** — uwagi do całego spotkania,
- **klub** — uwagi trwałe, niezależne od meczu,
- **zdarzenie** — uwaga przypięta do konkretnego zagrania (wymaga wskazania meczu
  i odniesienia, np. `shot-12` albo `34:15`).

Tagi wpisuje się po przecinku. Wyszukiwarka przeszukuje treść, tytuł i tagi —
także krótkie słowa jak „SBZ".

Notatki przypięte do zdarzeń widać razem z meczem: **Mecze → wybrany mecz → notatki**.

---

## 7. Kluby i sezony

**Kluby** — nazwa, skrót, barwy, herb (PNG lub SVG do 2 MB), klucz klubu.
Zaznacz **To mój klub** przy własnym zespole. W polu **Nazwy w eksportach**
wpisz warianty pisowni z LiveTag — dzięki temu kolejne mecze rozpoznają się same.

**Sezony** — sezon polski biegnie od lipca do czerwca. Pierwszy sezon powstanie
sam przy imporcie meczu z datą. **Bieżący sezon** jest zawsze jeden i steruje
licznikiem meczów na Pulpicie.
