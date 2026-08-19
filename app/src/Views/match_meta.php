<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Meta meczu — krok przed diffem i generowaniem (Sesja 6 pkt 1).
 *
 * PRZECIWNIK Z LISTY, NIE Z WOLNEGO TEKSTU. Wiersz w `clubs` niesie herb,
 * barwy i `aliases_json` — nazwy, pod jakimi klub występuje w eksportach.
 * To one napędzają dopasowanie drużyn przy kolejnych importach.
 *
 * @var array<string,mixed>|null  $club   klub-tenant (nasza drużyna)
 * @var array<string,mixed>       $import
 * @var array<string,mixed>|null  $mecz
 * @var list<array<string,mixed>> $rywale
 * @var list<array<string,mixed>> $seasons
 */
$isHome = $mecz['is_home'] ?? null;
?>
<h1 class="h1"><?= View::e(View::t('meta.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <p class="hint"><?= View::e(View::t('meta.lead')) ?></p>

  <form method="post" action="/import/<?= (int) $import['id'] ?>/meta"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

    <?php /*
      NASZA DRUŻYNA NIE JEST POLEM. Bierze się z klubu-tenanta meczu —
      wpisywanie jej przy każdym imporcie byłoby drugim źródłem prawdy.
    */ ?>
    <dl class="facts">
      <dt><?= View::e(View::t('match.us')) ?></dt>
      <dd>
        <?= $club !== null
            ? View::e((string) $club['name'])
            : '<span class="muted">' . View::e(View::t('match.no_club')) . '</span>' ?>
      </dd>
    </dl>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('meta.rival')) ?></span>
      <select class="field__input" name="club_away_id">
        <option value=""><?= View::e(View::t('meta.rival.none')) ?></option>
        <?php foreach ($rywale as $r): ?>
          <option value="<?= (int) $r['id'] ?>"
                  <?= (int) ($mecz['club_away_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $r['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint"><?= View::e(View::t('meta.rival.hint')) ?></span>
    </label>

    <fieldset class="panel panel--zagniezdzony">
      <legend class="field__label"><?= View::e(View::t('meta.rival.new')) ?></legend>
      <p class="hint"><?= View::e(View::t('meta.rival.new.hint')) ?></p>

      <label class="field">
        <span class="field__label"><?= View::e(View::t('club.name')) ?></span>
        <input class="field__input" type="text" name="nowy_rywal" maxlength="120">
      </label>

      <div class="grid2">
        <label class="field">
          <span class="field__label"><?= View::e(View::t('club.color_primary')) ?></span>
          <input class="field__input field__color" type="color" name="color_primary" value="#2C6FE8">
        </label>
        <label class="field">
          <span class="field__label"><?= View::e(View::t('club.crest')) ?></span>
          <input class="field__input" type="file" name="crest" accept=".png,.svg">
        </label>
      </div>
    </fieldset>

    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('match.date')) ?></span>
        <input class="field__input" type="date" name="played_at"
               value="<?= View::e(substr((string) ($mecz['played_at'] ?? ''), 0, 10)) ?>">
      </label>

      <label class="field">
        <span class="field__label"><?= View::e(View::t('matches.season')) ?></span>
        <select class="field__input" name="season_id">
          <option value=""><?= View::e(View::t('meta.season.auto')) ?></option>
          <?php foreach ($seasons as $s): ?>
            <option value="<?= (int) $s['id'] ?>"
                    <?= (int) ($mecz['season_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
              <?= View::e((string) $s['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('meta.where')) ?></span>
      <select class="field__input" name="is_home">
        <?php /*
          TRZY STANY, NIE DWA. Eksport LiveTag nie niesie gospodarza i nie wolno
          go zgadywać — „nie wiemy" jest poprawną wartością dla całej historii.
        */ ?>
        <option value="" <?= $isHome === null ? 'selected' : '' ?>>
          <?= View::e(View::t('meta.where.unknown')) ?>
        </option>
        <option value="1" <?= (string) $isHome === '1' ? 'selected' : '' ?>>
          <?= View::e(View::t('meta.where.home')) ?>
        </option>
        <option value="0" <?= $isHome !== null && (string) $isHome === '0' ? 'selected' : '' ?>>
          <?= View::e(View::t('meta.where.away')) ?>
        </option>
      </select>
    </label>

    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('meta.score_us')) ?></span>
        <input class="field__input" type="number" min="0" max="99" name="score_us"
               value="<?= $mecz['score_us'] !== null ? (int) $mecz['score_us'] : '' ?>">
      </label>
      <label class="field">
        <span class="field__label"><?= View::e(View::t('meta.score_them')) ?></span>
        <input class="field__input" type="number" min="0" max="99" name="score_them"
               value="<?= $mecz['score_them'] !== null ? (int) $mecz['score_them'] : '' ?>">
      </label>
    </div>
    <p class="hint"><?= View::e(View::t('meta.score.hint')) ?></p>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('meta.competition')) ?></span>
      <input class="field__input" type="text" name="competition" maxlength="120"
             value="<?= View::e((string) ($mecz['competition'] ?? '')) ?>">
    </label>

    <button class="btn" type="submit"><?= View::e(View::t('meta.submit')) ?></button>
  </form>
</section>

<p><a class="link" href="/import/<?= (int) $import['id'] ?>"><?= View::e(View::t('common.back')) ?></a></p>
