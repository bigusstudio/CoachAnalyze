<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * KALENDARZ — widok miesięczny, BEZ SKRYPTU (sesja 7 pivotu „viewer").
 *
 * Przejście między miesiącami to zwykłe odsyłacze `?m=RRRR-MM`. Panel ma jeden
 * zatwierdzony plik JavaScript i jest to wyjątek na chmurki powiadomień
 * (CLAUDE.md §9) — kalendarz się do niego nie łapie.
 *
 * TYDZIEŃ ZACZYNA SIĘ W PONIEDZIAŁEK. Produkt jest polski i tak wygląda każdy
 * kalendarz, który klient ma na ścianie.
 *
 * MECZ BEZ DATY NIE TRAFIA DO ŻADNEGO DNIA i mówimy o tym osobno, licznikiem.
 * Powieszenie go na losowym dniu byłoby wymyśleniem daty (CLAUDE.md §8).
 *
 * @var array<string,mixed>       $club
 * @var \DateTimeImmutable        $miesiac
 * @var list<array<string,mixed>> $mecze
 * @var int                       $bezDaty
 * @var string                    $poprzedni
 * @var string                    $nastepny
 */
$poDniach = [];
foreach ($mecze as $m) {
    $dzien = substr((string) $m['played_at'], 0, 10);
    $poDniach[$dzien][] = $m;
}

$pierwszy = $miesiac;
$ile = (int) $miesiac->format('t');
// `N` daje 1 dla poniedziałku — siatka zaczyna się od pustych pól tygodnia.
$przesuniecie = (int) $pierwszy->format('N') - 1;
$dni = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'];
$link = static fn(string $m): string => '/kalendarz?klub=' . (int) $club['id'] . '&m=' . $m;
?>
<h1 class="h1"><?= View::e(View::t('nav.calendar')) ?></h1>
<p class="hint"><?= View::e((string) $club['name']) ?></p>

<section class="panel">
  <p class="acts2">
    <a class="btn s" href="<?= View::e($link($poprzedni)) ?>">&larr;</a>
    <b><?= View::e($miesiac->format('Y-m')) ?></b>
    <a class="btn s" href="<?= View::e($link($nastepny)) ?>">&rarr;</a>
  </p>

  <div class="tbl-scroll">
    <table class="tbl kal">
      <thead>
        <tr><?php foreach ($dni as $d): ?><th><?= View::e($d) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php
        $komorka = 0;
        $dzienNr = 1;
        $tygodnie = (int) ceil(($przesuniecie + $ile) / 7);
        for ($t = 0; $t < $tygodnie; $t++):
      ?>
        <tr>
          <?php for ($k = 0; $k < 7; $k++): ?>
            <?php
              $pusta = $komorka < $przesuniecie || $dzienNr > $ile;
              $data = $pusta ? null : $miesiac->format('Y-m-') . str_pad((string) $dzienNr, 2, '0', STR_PAD_LEFT);
              $komorka++;
            ?>
            <td<?= $pusta ? ' class="muted"' : '' ?>>
              <?php if (!$pusta): ?>
                <span class="muted"><?= $dzienNr ?></span>
                <?php foreach ($poDniach[$data] ?? [] as $m): ?>
                  <div>
                    <a class="link" href="/sezon/mecz/<?= (int) $m['id'] ?>">
                      <?= View::e((string) ($m['away_name'] ?? View::t('match.no_club'))) ?>
                    </a>
                    <small class="muted"><?= View::e(View::t('status.' . (string) $m['status'])) ?></small>
                  </div>
                <?php endforeach; ?>
                <?php $dzienNr++; ?>
              <?php endif; ?>
            </td>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <?php if ($bezDaty > 0): ?>
    <p class="hint"><?= View::e(View::t('kalendarz.bez_daty', $bezDaty)) ?></p>
  <?php endif; ?>
</section>
