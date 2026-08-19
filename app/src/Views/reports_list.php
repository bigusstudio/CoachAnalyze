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

<section class="panel">
  <form class="filtr" method="get" action="<?= View::e($basePath) ?>">
    <?php if ($club === null): ?>
    <label class="field">
      <span class="field__label"><?= View::e(View::t('reports.club')) ?></span>
      <select class="field__input" name="klub">
        <option value=""><?= View::e(View::t('reports.all')) ?></option>
        <?php foreach ($clubs as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['klub'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('reports.season')) ?></span>
      <select class="field__input" name="sezon">
        <option value=""><?= View::e(View::t('reports.all')) ?></option>
        <?php foreach ($seasons as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) ($filters['sezon'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $s['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('reports.sort')) ?></span>
      <select class="field__input" name="sort">
        <option value="date_desc" <?= $filters['sort'] === 'date_desc' ? 'selected' : '' ?>>
          <?= View::e(View::t('reports.sort.date_desc')) ?>
        </option>
        <option value="date_asc" <?= $filters['sort'] === 'date_asc' ? 'selected' : '' ?>>
          <?= View::e(View::t('reports.sort.date_asc')) ?>
        </option>
      </select>
    </label>

    <button class="btn" type="submit"><?= View::e(View::t('reports.filter')) ?></button>
    <a class="link" href="<?= View::e($basePath) ?>"><?= View::e(View::t('reports.clear')) ?></a>
  </form>
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
          <th scope="col"><?= View::e(View::t('reports.col.generated')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.match')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.season')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.engine')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.link')) ?></th>
          <th scope="col" class="num"><?= View::e(View::t('reports.col.views')) ?></th>
          <th scope="col"><?= View::e(View::t('reports.col.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= View::e(substr((string) $r['generated_at'], 0, 16)) ?></td>

            <td>
              <?php /*
                Kolumny nazywają się „Nasza drużyna" i „Rywal", nie
                „Gospodarz"/„Gość" — eksport LiveTag nie niesie informacji o tym,
                kto grał u siebie, a udawanie jej kłamałoby przy każdym wyjeździe.
              */ ?>
              <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>/historia">
                <?= View::e(trim((string) ($r['home_name'] ?? '')) ?: View::t('common.unknown')) ?>
                —
                <?= View::e(trim((string) ($r['away_name'] ?? '')) ?: View::t('common.unknown')) ?>
              </a>

              <?php if ((int) $r['siblings'] > 1): ?>
                <?php /*
                  Jeden mecz miewa kilka raportów po regeneracji. Starszych nie
                  ukrywamy — pytanie „dlaczego raport z marca pokazywał inną
                  liczbę" musi mieć odpowiedź. Zamiast tego mówimy, który jest
                  najnowszy, żeby nikt nie wysłał zarządowi nieaktualnego.
                */ ?>
                <?php if (!empty($r['is_latest'])): ?>
                  <span class="tag tag--latest"><?= View::e(View::t('reports.latest')) ?></span>
                <?php else: ?>
                  <span class="tag tag--older" title="<?= View::e(View::t('reports.older.hint')) ?>">
                    <?= View::e(View::t('reports.older')) ?>
                  </span>
                <?php endif; ?>
                <span class="hint"><?= View::e(View::t('reports.siblings', (int) $r['siblings'])) ?></span>
              <?php endif; ?>
            </td>

            <td><?= $r['season_label'] !== null
                    ? View::e((string) $r['season_label'])
                    : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>

            <td>
              <?= View::e((string) ($r['engine_version'] ?? '')) ?: '<span class="muted">'
                  . View::e(View::t('common.dash')) . '</span>' ?>
              <?php /*
                BADGE WERSJI TEMPLATU (Sesja 6 informacyjnie, Sesja 7 z akcją).

                Nieaktualny raport pokazuje OBIE liczby: własną i aktualną klubu.
                Sam numer nie niesie informacji, czy jest z czym coś robić —
                dopiero zestawienie zamienia go w powód do kliknięcia.

                NULL znaczy „raport sprzed ery templatów". Gdy klub nie ma
                jeszcze templatu, to fakt historyczny i nic więcej; gdy ma —
                raport jest nieaktualny tak samo jak każdy starszy.
              */ ?>
              <br>
              <?php if (!empty($r['tpl_outdated'])): ?>
                <span class="tag tag--older" title="<?= View::e(View::t('recalc.act.hint')) ?>">
                  <?= $r['template_version'] === null
                      ? View::e(View::t('reports.tplv.outdated.none', (int) $r['tpl_current']))
                      : View::e(View::t(
                          'reports.tplv.outdated',
                          (int) $r['template_version'],
                          (int) $r['tpl_current']
                      )) ?>
                </span>
              <?php else: ?>
                <span class="tag <?= $r['template_version'] === null ? 'tag--older' : '' ?>">
                  <?= $r['template_version'] === null
                      ? View::e(View::t('reports.tplv.none'))
                      : View::e(View::t('reports.tplv', (int) $r['template_version'])) ?>
                </span>
              <?php endif; ?>
            </td>

            <td><?= View::linkStatus((string) $r['link_stan']) ?></td>

            <td class="num"><?= (int) $r['views'] ?></td>

            <td class="akcje">
              <a class="link" href="/raport/<?= (int) $r['id'] ?>"><?= View::e(View::t('reports.act.open')) ?></a>

              <a class="link" href="/raport/<?= (int) $r['id'] ?>/udostepnij">
                <?= View::e(View::t('reports.act.share')) ?>
              </a>

              <?php if ($r['link_stan'] === 'active' && $r['link_id'] !== null): ?>
                <form class="inline" method="post" action="/link/<?= (int) $r['link_id'] ?>/odwolaj">
                  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                  <input type="hidden" name="powrot" value="<?= View::e($basePath) ?>">
                  <button class="link link--danger" type="submit">
                    <?= View::e(View::t('reports.act.revoke')) ?>
                  </button>
                </form>
              <?php endif; ?>

              <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/ponow">
                <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <button class="link" type="submit"><?= View::e(View::t('reports.act.regen')) ?></button>
              </form>

              <?php /*
                PRZELICZ — tylko przy raporcie starszym niż templat klubu.
                Przycisk przy raporcie aktualnym byłby zaproszeniem do
                kilkudziesięciu sekund pracy silnika bez żadnej zmiany w wyniku.

                Bez surowych plików pokazujemy POWÓD, a nie wyszarzony przycisk
                bez wyjaśnienia — i od razu drogę wyjścia (CLAUDE.md §8: brak
                danych ma być widoczny, nie zamaskowany).
              */ ?>
              <?php if (!empty($r['tpl_outdated'])): ?>
                <?php if (!empty($r['raw_ready'])): ?>
                  <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/przelicz">
                    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <input type="hidden" name="powrot" value="<?= View::e($basePath) ?>">
                    <button class="link" type="submit" title="<?= View::e(View::t('recalc.act.hint')) ?>">
                      <?= View::e(View::t('recalc.act')) ?>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="muted" title="<?= View::e(View::t('recalc.blocked.hint')) ?>">
                    <?= View::e(View::t('recalc.blocked')) ?>
                  </span>
                  <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>/wgraj">
                    <?= View::e(View::t('recalc.blocked.act')) ?>
                  </a>
                <?php endif; ?>
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
