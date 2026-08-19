<?php
declare(strict_types=1);

use CoachAnalyze\Jobs;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Wskaźnik pracy kolejki — jeden komponent dla wszystkich operacji, na które
 * się czeka: generowanie po imporcie, przykładowy raport z konfiguratora,
 * przeliczenie pojedyncze i przeliczenie zbiorcze.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * RENDERUJE GO SERWER, SKRYPT GO TYLKO OŻYWIA.
 *
 * Bez JavaScriptu etapy, czas i komunikat błędu są w HTML-u wysłanym przez
 * serwer, a strona odświeża się przez `<meta http-equiv="refresh">` z layoutu.
 * Ze skryptem to samo aktualizuje się co kilka sekund bez przeładowania.
 * Skrypt PRZYSPIESZA, nie warunkuje (CLAUDE.md §9).
 *
 * ŻADNYCH PROCENTÓW. Nie znamy postępu renderu — pasek stojący na 87% kłamie
 * bardziej niż brak paska. Etapy są dyskretne i każdy z nich jest prawdziwy.
 *
 * TEKSTY PRZEZ `data-*`, nie w skrypcie: wersja anglojęzyczna nie ma wymagać
 * ruszania kodu. Skrypt przełącza klasy i wstawia liczby, nigdy zdania.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @var array<string,mixed>      $job        wiersz zadania
 * @var string|null              $resultUrl  dokąd po „Gotowe"; null = donikąd
 * @var bool|null                $autoGo     czy skrypt ma sam przejść do wyniku
 */
$resultUrl = $resultUrl ?? null;
$autoGo    = $autoGo ?? true;

$etapBiezacy = Jobs::stage((string) $job['status']);
$sekundy     = Jobs::elapsed($job);
$wolno       = $etapBiezacy === 'queued' && $sekundy > Jobs::PROG_WOLNO;

/*
 * Trzecia pozycja to „Gotowe" ALBO „Błąd" — nigdy obie naraz. Lista etapów ma
 * pokazywać drogę, którą zadanie faktycznie przeszło, a nie katalog możliwości.
 */
$etapy = [
    'queued'     => View::t('work.stage.queued'),
    'processing' => View::t('work.stage.processing'),
    $etapBiezacy === 'failed' ? 'failed' : 'done'
        => View::t($etapBiezacy === 'failed' ? 'work.stage.failed' : 'work.stage.done'),
];

/** Kolejność etapów — po niej rozstrzygamy, co jest już za nami. */
$porzadek = array_keys($etapy);
$indeks   = array_search($etapBiezacy, $porzadek, true);
$indeks   = $indeks === false ? 0 : $indeks;

$mmss = sprintf('%d:%02d', intdiv($sekundy, 60), $sekundy % 60);
?>
<section class="wskaznik wskaznik--<?= View::e($etapBiezacy) ?>"
         role="status"
         aria-live="polite"
         aria-label="<?= View::e(View::t('work.aria')) ?>"
         data-zadanie="<?= (int) $job['id'] ?>"
         data-zadanie-punkt="/zadania/<?= (int) $job['id'] ?>/stan"
         <?php /* Punkt odniesienia dla licznika. Sekundy liczy SERWER i wysyła
                  jako liczbę — zegar przeglądarki bywa przestawiony o godziny,
                  a licznik liczony z jej czasu pokazywałby wartość ujemną. */ ?>
         data-zadanie-czas="<?= (int) $sekundy ?>"
         data-zadanie-auto="<?= $autoGo ? '1' : '0' ?>"
         <?php if ($resultUrl !== null): ?>
         data-zadanie-wynik="<?= View::e($resultUrl) ?>"
         <?php endif; ?>>

  <ol class="wskaznik__etapy">
    <?php $i = 0; foreach ($etapy as $klucz => $etykieta): ?>
      <li class="wskaznik__etap<?= $i < $indeks ? ' is-za-nami' : '' ?><?= $i === $indeks ? ' is-teraz' : '' ?>"
          data-etap="<?= View::e((string) $klucz) ?>">
        <?php /* Puls jest OZDOBĄ STANU, nie treścią — czytnik ekranu ma go
                 pominąć i przeczytać samą etykietę etapu. */ ?>
        <span class="wskaznik__puls" aria-hidden="true"></span>
        <?= View::e((string) $etykieta) ?>
      </li>
    <?php $i++; endforeach; ?>
  </ol>

  <p class="wskaznik__czas">
    <?= View::e(View::t('work.elapsed')) ?>
    <b data-rola="czas"><?= View::e($mmss) ?></b>
  </p>

  <?php /*
    TIMEOUT UCZCIWOŚCI. Cron chodzi co minutę, więc kilkadziesiąt sekund
    czekania jest normą. Po trzech minutach w kolejce mówimy wprost, że coś
    stoi, i tłumaczymy dlaczego — zamiast dalej kręcić pulsem w nieskończoność.

    Akapit jest w HTML-u ZAWSZE, tylko ukryty: skrypt go odsłania, nie tworzy.
    Dzięki temu w skrypcie nie ma ani jednego polskiego zdania.
  */ ?>
  <p class="wskaznik__uwaga" data-rola="uwaga"<?= $wolno ? '' : ' hidden' ?>>
    <strong><?= View::e(View::t('work.slow')) ?></strong>
    <span class="hint"><?= View::e(View::t('work.slow.hint')) ?></span>
  </p>

  <?php /*
    STAN KOŃCOWY RENDERUJE SERWER, ZAWSZE — nawet gdy to skrypt go wykrył.

    Kusiło, żeby trzymać tu ukryty komunikat błędu i ukryty formularz „Ponów"
    i tylko je odsłaniać. Byłoby to jednak DRUGIE miejsce, w którym powstaje
    stan końcowy zadania, a przy okazji strona zadania w toku niosłaby
    w HTML-u przycisk ponowienia — czego pilnuje `test_4a` i słusznie:
    akcja obecna w dokumencie jest obecna, choćby była niewidoczna.

    Zamiast tego skrypt, gdy zobaczy stan końcowy, przechodzi do wyniku albo
    przeładowuje stronę — a wtedy poniższe gałęzie renderuje serwer, tak samo
    jak przy wyłączonym skrypcie. Jeden renderer, jedno źródło prawdy.
  */ ?>
  <?php if ($etapBiezacy === 'failed'): ?>
    <p class="wskaznik__blad">
      <?= View::e(Jobs::errorLine($job['error_text'] ?? null) ?? '') ?>
    </p>
  <?php endif; ?>

  <div class="wskaznik__akcje">
    <?php if ($etapBiezacy === 'done' && $resultUrl !== null): ?>
      <a class="btn" href="<?= View::e($resultUrl) ?>"><?= View::e(View::t('work.open')) ?></a>
    <?php endif; ?>

    <?php if ($etapBiezacy === 'failed'): ?>
      <?php /* Pełnoprawny formularz z tokenem CSRF — działa tak samo bez skryptu. */ ?>
      <form class="inline" method="post" action="/zadania/<?= (int) $job['id'] ?>/ponow">
        <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <button class="btn btn--ghost" type="submit"><?= View::e(View::t('work.retry')) ?></button>
      </form>
    <?php endif; ?>

    <a class="link" href="/zadania/<?= (int) $job['id'] ?>"><?= View::e(View::t('work.details')) ?></a>
  </div>
</section>
