<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Edycja hasła indeksu — zapis tworzy NOWĄ WERSJĘ klubową.
 *
 * @var array<string,mixed> $term
 * @var array<string,mixed> $club
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('index.edit.title', (string) $term['name'])) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<p class="hint hint--block"><?= View::e(View::t('index.edit.why', (string) $club['name'])) ?></p>

<form method="post" action="/indeks/<?= View::e((string) $term['slug']) ?>?klub=<?= (int) $club['id'] ?>">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <section class="panel">
    <p class="hint">
      <?= View::e(View::t('index.col.concept')) ?>:
      <code class="tag-nazwa"><?= View::e((string) $term['concept']) ?></code>
      — <?= View::e(View::t('index.edit.concept_fixed')) ?>
    </p>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.col.name')) ?></span>
      <input class="field__input" type="text" name="name" required maxlength="120"
             value="<?= View::e((string) $term['name']) ?>">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.definition')) ?></span>
      <textarea class="field__input" name="definition" rows="3" required><?=
        View::e((string) $term['definition']) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.formula')) ?></span>
      <textarea class="field__input" name="formula" rows="2"><?=
        View::e((string) ($term['formula'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.example')) ?></span>
      <textarea class="field__input" name="example" rows="2"><?=
        View::e((string) ($term['example'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.interpretation')) ?></span>
      <textarea class="field__input" name="interpretation" rows="3"><?=
        View::e((string) ($term['interpretation'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.source')) ?></span>
      <textarea class="field__input" name="source" rows="2"><?=
        View::e((string) ($term['source'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.estimated_note')) ?></span>
      <textarea class="field__input" name="estimated_note" rows="3"
                placeholder="<?= View::e(View::t('index.field.estimated_note.hint')) ?>"><?=
        View::e((string) ($term['estimated_note'] ?? '')) ?></textarea>
    </label>

    <p class="hint"><?= View::e(View::t('index.edit.versioning')) ?></p>

    <div class="actions">
      <button class="btn" type="submit"><?= View::e(View::t('index.edit.submit')) ?></button>
      <a class="link" href="/indeks/<?= View::e((string) $term['slug']) ?>?klub=<?= (int) $club['id'] ?>">
        <?= View::e(View::t('common.back')) ?>
      </a>
    </div>
  </section>
</form>
