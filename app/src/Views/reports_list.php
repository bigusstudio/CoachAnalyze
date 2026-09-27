<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Wszystkie wygenerowane raporty, niezależnie od meczu.
 *
 * BEZ KONTEKSTU KLUBU (`$club === null`): globalna `/raporty`. Z KONTEKSTEM
 * (Sesja 2, `/klub/{id}/raporty`): filtr „klub" znika, zakres wchodzi z adresu.
 *
 * @var list<array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var list<array<string,mixed>> $clubs
 * @var list<array<string,mixed>> $seasons
 * @var array<string,mixed> $filters
 * @var string|null $notice
 * @var string|null $error
 * @var array<string,mixed>|null $club
 */
$club ??= null;
$basePath = $club !== null ? '/klub/' . (int) $club['id'] . '/raporty' : '/raporty';

/** Adres z podmienionym jednym parametrem — reszta filtra zostaje. */
$link = static function (array $zmiany) use ($filters, $basePath): string {
    $q = array_filter(array_merge($filters, $zmiany), static fn($v) => $v !== null && $v !== '');
    return $basePath . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e($club !== null ? View::t('nav.reports') : View::t('reports.title')) ?></h1>
  <span class="hint"><?= View::e(View::t('reports.count', $total)) ?></span>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php
  /*
   * FILTRY BEZ „POKAŻ" (golden layout W0). Wybór to odsyłacz — działa bez
   * skryptu (CLAUDE.md §9) i nie wymaga drugiego kliknięcia. Formularz
   * z przyciskiem „Pokaż" kazał wybrać, a potem jeszcze potwierdzić wybór.
   */
  $op = View::op();
  $chip = static fn(string $etykieta, string $adres, bool $wlaczony): string =>
      '<a class="fchip' . ($wlaczony ? ' fchip--on' : '') . '" href="' . View::e($adres) . '"'
      . ($wlaczony ? ' aria-current="true"' : '') . '>' . View::e($etykieta) . '</a>';
?>
<section class="panel">
  <div class="fchipy">
    <?php if ($club === null && count($clubs) > 1): ?>
      <p class="fchipy__rz">
        <span class="field__label"><?= View::e(View::t('reports.club')) ?></span>
        <?= $chip(View::t('reports.all'), $link(['klub' => null, 'strona' => null]), ($filters['klub'] ?? '') === '') ?>
        <?php foreach ($clubs as $c): ?>
          <?= $chip((string) $c['name'], $link(['klub' => (int) $c['id'], 'strona' => null]), (int) ($filters['klub'] ?? 0) === (int) $c['id']) ?>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <p class="fchipy__rz">
      <span class="field__label"><?= View::e(View::t('reports.season')) ?></span>
      <?= $chip(View::t('reports.all'), $link(['sezon' => null, 'strona' => null]), ($filters['sezon'] ?? '') === '') ?>
      <?php foreach ($seasons as $se): ?>
        <?= $chip((string) $se['label'], $link(['sezon' => (int) $se['id'], 'strona' => null]), (int) ($filters['sezon'] ?? 0) === (int) $se['id']) ?>
      <?php endforeach; ?>
    </p>
    <p class="fchipy__rz">
      <span class="field__label"><?= View::e(View::t('reports.sort')) ?></span>
      <?= $chip(View::t('reports.sort.date_desc'), $link(['sort' => 'date_desc', 'strona' => null]), $filters['sort'] === 'date_desc') ?>
      <?= $chip(View::t('reports.sort.date_asc'), $link(['sort' => 'date_asc', 'strona' => null]), $filters['sort'] === 'date_asc') ?>
    </p>
  </div>
</section>

<?php if ($rows === []): ?>
  <?php /* Pusty stan opisowy: mówi, czego brakuje i co zrobić, a nie „brak danych". */ ?>
  <p class="empty"><?= View::e(View::t(
      ($filters['klub'] ?? '') !== '' || ($filters['sezon'] ?? '') !== ''
          ? 'reports.empty.filtered'
          : 'reports.empty'
  )) ?></p>
<?php else: ?>
  <section class="panel">
    <table class="tbl">
      <caption class="sr-only"><?= View::e(View::t('reports.title')) ?></caption>
      <thead>
        <tr>
          <th scope="col"><?= View::e(View::t('reports.col.match')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.season')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.generated')) ?></th>
          <?php if ($op): ?>
            <th scope="col"><?= View::e(View::t('reports.col.engine')) ?></th>
          <?php endif; ?>
          <th scope="col"><?= View::e(View::t('reports.col.link')) ?></th>
          <th scope="col" class="num"><?= View::e(View::t('reports.col.views')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <?php /* WIERSZ = MECZ. Nazwa prowadzi na kartę meczu — rodzica
                       raportu; sam raport otwiera przycisk w ostatniej kolumnie.
                       Kolumny „Nasza drużyna"/„Rywal", nie „Gospodarz"/„Gość":
                       eksport LiveTag nie niesie informacji, kto grał u siebie. */ ?>
              <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>">
                <?= View::e(trim((string) ($r['home_name'] ?? '')) ?: View::t('common.unknown')) ?>
                —
                <?= View::e(trim((string) ($r['away_name'] ?? '')) ?: View::t('common.unknown')) ?>
              </a>
            </td>

            <td><?= $r['season_label'] !== null
                    ? View::e((string) $r['season_label'])
                    : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>

            <td><?= View::e(substr((string) $r['generated_at'], 0, 16)) ?></td>

            <?php if ($op): ?>
              <td>
                <?= View::e((string) ($r['engine_version'] ?? '')) ?: '<span class="muted">'
                    . View::e(View::t('common.dash')) . '</span>' ?>
                <br>
                <?php if (!empty($r['tpl_outdated'])): ?>
                  <span class="tag tag--older" title="<?= View::e(View::t('recalc.act.hint')) ?>">
                    <?= $r['template_version'] === null
                        ? View::e(View::t('reports.tplv.outdated.none', (int) $r['tpl_current']))
                        : View::e(View::t('reports.tplv.outdated', (int) $r['template_version'], (int) $r['tpl_current'])) ?>
                  </span>
                <?php else: ?>
                  <span class="tag <?= $r['template_version'] === null ? 'tag--older' : '' ?>">
                    <?= $r['template_version'] === null
                        ? View::e(View::t('reports.tplv.none'))
                        : View::e(View::t('reports.tplv', (int) $r['template_version'])) ?>
                  </span>
                <?php endif; ?>
              </td>
            <?php endif; ?>

            <td><?= View::linkStatus((string) $r['link_stan']) ?></td>

            <td class="num"><?= (int) $r['views'] ?></td>

            <td class="akcje">
              <a class="btn s" href="/raport/<?= (int) $r['id'] ?>"><?= View::e(View::t('card.act.open')) ?></a>

              <?php if ($op): ?>
                <?php if (!empty($r['tpl_outdated'])): ?>
                  <?php if (!empty($r['raw_ready'])): ?>
                    <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/przelicz">
                      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                      <input type="hidden" name="powrot" value="<?= View::e($link([])) ?>">
                      <button class="link" type="submit" title="<?= View::e(View::t('recalc.act.hint')) ?>">
                        <?= View::e(View::t('recalc.act')) ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="muted" title="<?= View::e(View::t('recalc.blocked.hint')) ?>">
                      <?= View::e(View::t('recalc.blocked')) ?>
                    </span>
                    <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>?zakladka=pliki">
                      <?= View::e(View::t('recalc.blocked.act')) ?>
                    </a>
                  <?php endif; ?>
                <?php endif; ?>
                <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/ponow">
                  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                  <button class="link" type="submit"><?= View::e(View::t('reports.act.regen')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <?php if ($pages > 1): ?>
    <nav class="strony" aria-label="<?= View::e(View::t('reports.pages')) ?>">
      <?php if ($page > 1): ?>
        <a class="link" href="<?= View::e($link(['strona' => $page - 1])) ?>">
          <?= View::e(View::t('reports.prev')) ?>
        </a>
      <?php endif; ?>

      <span class="hint"><?= View::e(View::t('reports.page_of', $page, $pages)) ?></span>

      <?php if ($page < $pages): ?>
        <a class="link" href="<?= View::e($link(['strona' => $page + 1])) ?>">
          <?= View::e(View::t('reports.next')) ?>
        </a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
