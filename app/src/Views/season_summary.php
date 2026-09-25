<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * SEZON › SUMA (sesja 7 pivotu „viewer").
 *
 * SUMA TO TA SAMA DEFINICJA METRYKI BEZ FILTRA MECZU, nie osobne zestawienie.
 * Gdyby liczyła się inaczej, mogłaby się nie zgadzać z sumą kolejek pokazaną
 * niżej — a wtedy nie wiadomo, której liczbie wierzyć.
 *
 * KRESKA TO NIE ZERO. Metryka bez pokrycia w katalogu tagów klubu ma `value`
 * `null` i wpis w `coverage`: klub, który nie taguje wejść w SBZ, ma
 * zobaczyć „nie ma czego liczyć", a nie „0 wejść w SBZ" (migracja 015).
 *
 * @var array<string,mixed>       $club
 * @var array<string,mixed>       $metryki
 * @var list<array<string,mixed>> $kolejki
 * @var string|null               $sezonLabel
 */
$dziesietna = static fn($w): string => number_format((float) $w, 2, ',', ' ');
$wartosc = static function (array $m) use ($dziesietna): string {
    if ($m['value'] === null) {
        return View::t('common.dash');
    }
    // Wskaźnik ma mianownik — pokazujemy go jako procent z ułamkiem obok.
    if (($m['d'] ?? null) !== null && (int) $m['d'] > 0) {
        return round(100 * (float) $m['value']) . '% (' . (int) $m['n'] . '/' . (int) $m['d'] . ')';
    }
    return is_float($m['value']) && (float) $m['value'] != (int) $m['value']
        ? $dziesietna($m['value'])
        : (string) (int) $m['value'];
};
?>
<h1 class="h1"><?= View::e(View::t('sezon.suma.title')) ?></h1>
<p class="hint">
  <?= View::e((string) $club['name']) ?>
  <?php if ($sezonLabel !== null): ?> · <?= View::e($sezonLabel) ?><?php endif; ?>
  · <?= View::e(View::t('sezon.suma.hint', count($kolejki))) ?>
</p>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('sezon.suma.metryki')) ?></h2>
  <?php if (($metryki['metrics'] ?? []) === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.pusto')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('sezon.suma.metryka')) ?></th>
            <th class="num"><?= View::e(View::t('sezon.suma.wartosc')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($metryki['metrics'] as $m): ?>
          <tr>
            <td><?= View::e((string) $m['label']) ?></td>
            <td class="num"><?= View::e($wartosc($m)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (($metryki['coverage'] ?? []) !== []): ?>
    <p class="hint">
      <?= View::e(View::t('sezon.suma.brak_pokrycia')) ?>
      <?php foreach ($metryki['coverage'] as $b): ?>
        <span class="tag"><?= View::e(implode(', ', (array) $b['missing_tags'])) ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('sezon.suma.per_kolejka')) ?></h2>
  <?php if ($kolejki === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.pusto')) ?></p>
  <?php else: ?>
    <?php
      // SUMY LICZONE Z TYCH SAMYCH WIERSZY, co tabela — zsumowanie kolumny
      // widocznej na ekranie jest jedyną sumą, którą da się sprawdzić wzrokiem.
      $sumaGoleUs = 0; $sumaGoleThem = 0; $sumaXgUs = 0.0; $sumaXgThem = 0.0;
      $sumaStrzalyUs = 0; $sumaStrzalyThem = 0;
      foreach ($kolejki as $k) {
          $sumaGoleUs += (int) $k['goals_us'];
          $sumaGoleThem += (int) $k['goals_them'];
          $sumaXgUs += (float) $k['xg_us'];
          $sumaXgThem += (float) $k['xg_them'];
          $sumaStrzalyUs += (int) $k['shots_us'];
          $sumaStrzalyThem += (int) $k['shots_them'];
      }
    ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('dash.col.round')) ?></th>
            <th><?= View::e(View::t('dash.col.match')) ?></th>
            <th><?= View::e(View::t('dash.col.result')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.xg')) ?></th>
            <th class="num"><?= View::e(View::t('dash.col.shots')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kolejki as $nr => $k): ?>
          <tr>
            <td>
              <?php if ($k['round'] !== null && $k['round'] !== ''): ?>
                <b><?= View::e(View::t('dash.round.prefix', (string) $k['round'])) ?></b>
              <?php else: ?>
                <span class="muted"><?= View::e((string) ($nr + 1)) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <a class="link" href="/sezon/mecz/<?= (int) $k['id'] ?>">
                <?= View::e((string) ($k['away_name'] ?? View::t('match.no_club'))) ?>
              </a>
            </td>
            <td><?= View::e((int) $k['goals_us'] . ':' . (int) $k['goals_them']) ?></td>
            <td class="num"><?= View::e($dziesietna($k['xg_us']) . ' : ' . $dziesietna($k['xg_them'])) ?></td>
            <td class="num"><?= View::e((int) $k['shots_us'] . ' : ' . (int) $k['shots_them']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><b><?= View::e(View::t('dash.season_sum')) ?></b></td>
            <td><b><?= View::e($sumaGoleUs . ':' . $sumaGoleThem) ?></b></td>
            <td class="num"><b><?= View::e($dziesietna($sumaXgUs) . ' : ' . $dziesietna($sumaXgThem)) ?></b></td>
            <td class="num"><b><?= View::e($sumaStrzalyUs . ' : ' . $sumaStrzalyThem) ?></b></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</section>

<p><a class="link" href="/sezon?klub=<?= (int) $club['id'] ?>"><?= View::e(View::t('sezon.title')) ?></a></p>
