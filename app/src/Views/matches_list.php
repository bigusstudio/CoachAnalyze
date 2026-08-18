<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Biblioteka meczów: filtr, sortowanie, stronicowanie.
 *
 * BEZ KONTEKSTU KLUBU (`$club === null`): globalna `/mecze`, wszystkie tenanty
 * naraz, z filtrem po klubie. Z KONTEKSTEM (`$club !== null`, Sesja 2):
 * `/klub/{id}/mecze` — zakres jest już ustalony przez adres, więc filtr
 * „klub" znika, a formularz i stronicowanie zostają w tym samym scope'ie.
 *
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $wynik
 * @var list<array<string,mixed>> $clubs
 * @var list<array<string,mixed>> $seasons
 * @var array<string,mixed> $filtr
 * @var string|null $notice
 * @var array<string,mixed>|null $club
 */
$club ??= null;
$rows = $wynik['rows'];
$basePath = $club !== null ? '/klub/' . (int) $club['id'] . '/mecze' : '/mecze';

/** Adres z podmienionym jednym parametrem — reszta filtra zostaje. */
$link = static function (array $zmiany) use ($filtr, $basePath): string {
    $q = array_filter(array_merge($filtr, $zmiany), static fn($v) => $v !== null && $v !== '');
    return $basePath . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e($club !== null ? View::t('nav.matches') : View::t('matches.title')) ?></h1>
  <a class="btn btn--ghost" href="<?= $club !== null ? '/klub/' . (int) $club['id'] . '/import' : '/import' ?>">
    <?= View::e(View::t('import.nav')) ?>
  </a>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<section class="panel">
  <form class="filtr" method="get" action="<?= View::e($basePath) ?>">
    <?php if ($club === null): ?>
    <label class="field">
      <span class="field__label"><?= View::e(View::t('matches.club')) ?></span>
      <select class="field__input" name="klub">
        <option value=""><?= View::e(View::t('matches.all')) ?></option>
        <?php foreach ($clubs as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($filtr['klub'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('matches.season')) ?></span>
      <select class="field__input" name="sezon">
        <option value=""><?= View::e(View::t('matches.all')) ?></option>
        <?php foreach ($seasons as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) ($filtr['sezon'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $s['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('matches.sort')) ?></span>
      <select class="field__input" name="sort">
        <?php foreach (['data_desc', 'data_asc', 'status_asc', 'status_desc'] as $s): ?>
          <option value="<?= View::e($s) ?>" <?= ($filtr['sort'] ?? '') === $s ? 'selected' : '' ?>>
            <?= View::e(View::t('matches.sort.' . $s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <button class="btn" type="submit"><?= View::e(View::t('matches.filter')) ?></button>
  </form>
</section>

<section class="panel">
  <?php if ($rows === []): ?>
    <p class="empty">
      <?= View::e(View::t($wynik['total'] === 0 && empty($filtr['klub']) && empty($filtr['sezon'])
          ? 'dash.empty.matches'
          : 'matches.empty_filter')) ?>
    </p>
  <?php else: ?>
    <p class="hint"><?= View::e(View::t('matches.count', $wynik['total'], $wynik['page'], $wynik['pages'])) ?></p>
    <table class="tbl">
      <thead>
        <tr>
          <th><?= View::e(View::t('match.date')) ?></th>
          <th><?= View::e(View::t('match.us')) ?></th>
          <th><?= View::e(View::t('match.them')) ?></th>
          <th><?= View::e(View::t('matches.season')) ?></th>
          <th><?= View::e(View::t('match.status')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td><?= !empty($m['played_at'])
              ? View::e(substr((string) $m['played_at'], 0, 10))
              : '<span class="muted">' . View::e(View::t('match.no_date')) . '</span>' ?></td>
          <td><?= !empty($m['home_name'])
              ? View::e((string) $m['home_name'])
              : '<span class="muted">' . View::e(View::t('match.no_club')) . '</span>' ?></td>
          <td><?= !empty($m['away_name'])
              ? View::e((string) $m['away_name'])
              : '<span class="muted">' . View::e(View::t('match.no_club')) . '</span>' ?></td>
          <td><?= !empty($m['season_label'])
              ? View::e((string) $m['season_label'])
              : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>
          <td><?= View::status((string) $m['status']) ?></td>
          <td>
            <?php if (!empty($m['import_id'])): ?>
              <a class="link" href="/import/<?= (int) $m['import_id'] ?>"><?= View::e(View::t('matches.open')) ?></a>
            <?php else: ?>
              <span class="muted"><?= View::e(View::t('common.dash')) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($wynik['pages'] > 1): ?>
      <nav class="strony" aria-label="<?= View::e(View::t('matches.pages')) ?>">
        <?php if ($wynik['page'] > 1): ?>
          <a class="link" href="<?= View::e($link(['strona' => $wynik['page'] - 1])) ?>">
            &larr; <?= View::e(View::t('matches.prev')) ?>
          </a>
        <?php endif; ?>
        <span class="muted"><?= View::e(View::t('matches.page_of', $wynik['page'], $wynik['pages'])) ?></span>
        <?php if ($wynik['page'] < $wynik['pages']): ?>
          <a class="link" href="<?= View::e($link(['strona' => $wynik['page'] + 1])) ?>">
            <?= View::e(View::t('matches.next')) ?> &rarr;
          </a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
