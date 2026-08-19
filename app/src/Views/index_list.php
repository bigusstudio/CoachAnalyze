<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Indeks współczynników — lista haseł z wyszukiwarką.
 *
 * @var list<array<string,mixed>> $terms
 * @var string $q
 * @var array<string,mixed>|null $club
 * @var list<array<string,mixed>> $clubs
 * @var string|null $notice
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('index.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<p class="hint hint--block"><?= View::e(View::t('index.intro')) ?></p>

<?php if ($club !== null): ?>
  <p>
    <?php /* Hasła systemowe są stałą w kodzie; tutaj klub dopisuje własne. */ ?>
    <a class="btn" href="/indeks/nowe?klub=<?= (int) $club['id'] ?>">
      <?= View::e(View::t('index.new.link')) ?>
    </a>
  </p>
<?php endif; ?>

<form method="get" action="/indeks" class="filters">
  <label class="field field--slim">
    <span class="field__label"><?= View::e(View::t('index.search')) ?></span>
    <input class="field__input" type="search" name="q" value="<?= View::e($q) ?>"
           placeholder="<?= View::e(View::t('index.search.hint')) ?>">
  </label>

  <?php if (count($clubs) > 1): ?>
    <label class="field field--slim">
      <span class="field__label"><?= View::e(View::t('index.club')) ?></span>
      <select class="field__input" name="klub">
        <?php foreach ($clubs as $k): ?>
          <option value="<?= (int) $k['id'] ?>"
                  <?= $club !== null && (int) $club['id'] === (int) $k['id'] ? 'selected' : '' ?>>
            <?= View::e((string) $k['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <button class="btn btn--ghost" type="submit"><?= View::e(View::t('index.filter')) ?></button>
</form>

<?php if ($terms === []): ?>
  <p class="empty"><?= View::e(View::t('index.none', $q)) ?></p>
<?php else: ?>
  <section class="panel">
    <table class="tbl">
      <thead>
        <tr>
          <th scope="col"><?= View::e(View::t('index.col.name')) ?></th>
          <th scope="col"><?= View::e(View::t('index.col.concept')) ?></th>
          <th scope="col"><?= View::e(View::t('index.col.version')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($terms as $t): ?>
          <tr>
            <td>
              <a class="link" href="/indeks/<?= View::e((string) $t['slug'])
                  ?><?= $club !== null ? '?klub=' . (int) $club['id'] : '' ?>">
                <?= View::e((string) $t['name']) ?>
              </a>
              <?php if (($t['estimated_note'] ?? null) !== null): ?>
                <span class="hint" title="<?= View::e(View::t('index.estimated')) ?>">
                  · <?= View::e(View::t('index.estimated.short')) ?>
                </span>
              <?php endif; ?>
            </td>
            <td><code class="tag-nazwa"><?= View::e((string) $t['concept']) ?></code></td>
            <td>
              <?php /*
                TRZY STANY, NIE DWA. „Klubowe" i „nadpisuje systemowe" to nie to
                samo: pierwsze dokłada wskaźnik, drugie podmienia metodykę
                produktu pod tą samą nazwą. Czytelnik musi je rozróżnić, zanim
                weźmie definicję za obowiązującą w całym produkcie.
              */ ?>
              <?php if (!empty($t['is_default'])): ?>
                <span class="tag"><?= View::e(View::t('index.mark.system')) ?></span>
              <?php elseif (!empty($t['overrides_default'])): ?>
                <span class="tag tag--older" title="<?= View::e(View::t('index.mark.override.hint')) ?>">
                  <?= View::e(View::t('index.mark.override')) ?>
                </span>
              <?php else: ?>
                <span class="tag tag--done"><?= View::e(View::t('index.mark.club')) ?></span>
              <?php endif; ?>
              <span class="hint">
                <?= !empty($t['is_default'])
                    ? View::e(View::t('index.version.default'))
                    : View::e(View::t('index.version.club', (int) $t['version'])) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
