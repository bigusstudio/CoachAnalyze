<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Karta jednego meczu (sesja 7 pivotu „viewer").
 *
 * JEDNO MIEJSCE ZAMIAST CZTERECH. Meta, liczby i wszystko, co z meczem można
 * zrobić — dotąd rozrzucone po pokryciu importu, liście meczów, historii meczu
 * i hubie klubu. Operator wchodzi z paska sezonu i ma komplet pod ręką.
 *
 * KRESKA TO NIE ZERO. Mecz bez zdarzeń w bazie (raport sprzed sesji 2 pivotu,
 * import bez przeliczenia) pokazuje kreski i zdanie, skąd wezmą się liczby —
 * a nie same zera, które wyglądają jak mecz bez akcji.
 *
 * @var array<string,mixed>       $club
 * @var array<string,mixed>       $mecz
 * @var array<string,mixed>|null  $fakty
 * @var array<string,mixed>       $metryki
 * @var list<array<string,mixed>> $sklad
 * @var array<string,mixed>|null  $raport
 * @var array<string,mixed>|null  $import
 */
$dziesietna = static fn($w): string => number_format((float) $w, 2, ',', ' ');
$kreska = View::t('common.dash');
$wartosc = static function (array $m) use ($dziesietna, $kreska): string {
    if ($m['value'] === null) {
        return $kreska;
    }
    if (($m['d'] ?? null) !== null && (int) $m['d'] > 0) {
        return round(100 * (float) $m['value']) . '% (' . (int) $m['n'] . '/' . (int) $m['d'] . ')';
    }
    return is_float($m['value']) && (float) $m['value'] != (int) $m['value']
        ? $dziesietna($m['value'])
        : (string) (int) $m['value'];
};
$matchId = (int) $mecz['id'];
?>
<h1 class="h1">
  <?php if ($mecz['round'] !== null && $mecz['round'] !== ''): ?>
    <?= View::e(View::t('dash.round.prefix', (string) $mecz['round'])) ?> ·
  <?php endif; ?>
  <?= View::e((string) ($mecz['home_name'] ?? $club['name'])) ?>
  – <?= View::e((string) ($mecz['away_name'] ?? View::t('match.no_club'))) ?>
</h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('sezon.mecz.meta')) ?></h2>
  <div class="tbl-scroll">
    <table class="tbl">
      <tbody>
        <tr><td><?= View::e(View::t('match.date')) ?></td>
            <td><?= View::e((string) ($mecz['played_at'] ?? $kreska)) ?></td></tr>
        <tr><td><?= View::e(View::t('meta.round')) ?></td>
            <td><?= View::e((string) ($mecz['round'] ?? '') ?: $kreska) ?></td></tr>
        <tr><td><?= View::e(View::t('meta.where')) ?></td>
            <td>
              <?php /* Trzy stany, nie dwa: puste pole to NIE „wyjazd" (migracja 012). */ ?>
              <?= View::e($mecz['is_home'] === null
                  ? View::t('meta.where.unknown')
                  : ((int) $mecz['is_home'] === 1 ? View::t('meta.where.home') : View::t('meta.where.away'))) ?>
            </td></tr>
        <tr><td><?= View::e(View::t('dash.col.status')) ?></td>
            <td><?= View::e(View::t('status.' . (string) $mecz['status'])) ?></td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('sezon.mecz.liczby')) ?></h2>
  <?php if ($fakty === null): ?>
    <p class="empty"><?= View::e(View::t('sezon.mecz.bez_zdarzen')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th></th>
            <th class="num"><?= View::e((string) $club['name']) ?></th>
            <th class="num"><?= View::e((string) ($mecz['away_name'] ?? View::t('match.no_club'))) ?></th>
          </tr>
        </thead>
        <tbody>
          <tr><td><?= View::e(View::t('dash.col.result')) ?></td>
              <td class="num"><?= (int) $fakty['us']['goals'] ?></td>
              <td class="num"><?= (int) $fakty['them']['goals'] ?></td></tr>
          <tr><td><?= View::e(View::t('dash.col.xg')) ?></td>
              <td class="num"><?= View::e($dziesietna($fakty['us']['xg'])) ?></td>
              <td class="num"><?= View::e($dziesietna($fakty['them']['xg'])) ?></td></tr>
          <tr><td><?= View::e(View::t('dash.col.shots')) ?></td>
              <td class="num"><?= (int) $fakty['us']['shots'] ?></td>
              <td class="num"><?= (int) $fakty['them']['shots'] ?></td></tr>
        </tbody>
      </table>
    </div>

    <?php if (($metryki['metrics'] ?? []) !== []): ?>
      <div class="tbl-scroll">
        <table class="tbl">
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
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('roster.title')) ?></h2>
  <?php if ($sklad === []): ?>
    <p class="empty"><?= View::e(View::t('sezon.mecz.bez_skladu')) ?></p>
  <?php else: ?>
    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('roster.col.player')) ?></th>
            <th class="num"><?= View::e(View::t('roster.col.number')) ?></th>
            <th><?= View::e(View::t('roster.col.position')) ?></th>
            <th class="num"><?= View::e(View::t('roster.col.minutes')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sklad as $z): ?>
          <tr>
            <td><?= View::e((string) $z['player']) ?><?= !empty($z['is_starter'])
                  ? ' <span class="muted">· ' . View::e(View::t('roster.col.starter')) . '</span>' : '' ?></td>
            <td class="num"><?= $z['number'] !== null ? (int) $z['number'] : View::e($kreska) ?></td>
            <td><?= View::e((string) ($z['position'] ?? '') ?: $kreska) ?></td>
            <td class="num"><?= $z['minutes'] !== null ? (int) $z['minutes'] : View::e($kreska) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('sezon.mecz.akcje')) ?></h2>
  <p class="acts2">
    <?php if ($raport !== null): ?>
      <a class="btn p" href="/raporty/<?= (int) $raport['id'] ?>"><?= View::e(View::t('sezon.mecz.raport')) ?></a>
    <?php else: ?>
      <span class="hint"><?= View::e(View::t('sezon.mecz.bez_raportu')) ?></span>
    <?php endif; ?>
    <a class="btn s" href="/mecze/<?= $matchId ?>/meta?powrot=/sezon/mecz/<?= $matchId ?>">
      <?= View::e(View::t('sezon.mecz.meta_edit')) ?>
    </a>
    <a class="btn s" href="/mecze/<?= $matchId ?>/historia"><?= View::e(View::t('sezon.mecz.historia')) ?></a>
    <?php if ($import !== null): ?>
      <a class="btn s" href="/import/<?= (int) $import['id'] ?>"><?= View::e(View::t('sezon.mecz.pokrycie')) ?></a>
    <?php endif; ?>
    <a class="btn s" href="/klub/<?= (int) $club['id'] ?>/przelicz"><?= View::e(View::t('sezon.mecz.przelicz')) ?></a>
  </p>
  <?php /* SLAJDY PNG generuje SAM RAPORT, w przeglądarce — przycisk jest w jego
           nagłówku. Drugi przycisk tutaj musiałby albo otworzyć raport, albo
           powtórzyć mechanizm po stronie panelu; pierwsze jest odsyłaczem, który
           już wyżej jest, drugie byłoby drugą implementacją eksportu. */ ?>
  <p class="hint"><?= View::e(View::t('sezon.mecz.slajdy_hint')) ?></p>
</section>

<p><a class="link" href="/sezon?klub=<?= (int) $club['id'] ?>"><?= View::e(View::t('sezon.title')) ?></a></p>
