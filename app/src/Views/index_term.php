<?php
declare(strict_types=1);

use CoachAnalyze\Auth;
use CoachAnalyze\Session;
use CoachAnalyze\Users;
use CoachAnalyze\View;

/**
 * Hasło indeksu — pełna treść.
 *
 * @var array<string,mixed> $term
 * @var array<string,mixed>|null $club
 * @var array<string,mixed>|null $systemowe  wersja systemowa przy nadpisaniu
 * @var string|null $notice
 * @var string|null $error
 */
$user = Auth::currentUser();
$systemowe = $systemowe ?? null;
?>
<h1 class="h1"><?= View::e((string) $term['name']) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<p class="hint">
  <?= View::e(View::t('index.col.concept')) ?>:
  <code class="tag-nazwa"><?= View::e((string) $term['concept']) ?></code>
  ·
  <?php if (!empty($term['is_default'])): ?>
    <span class="tag"><?= View::e(View::t('index.mark.system')) ?></span>
  <?php elseif (!empty($term['overrides_default'])): ?>
    <span class="tag tag--older"><?= View::e(View::t('index.mark.override')) ?></span>
  <?php else: ?>
    <span class="tag tag--done"><?= View::e(View::t('index.mark.club')) ?></span>
  <?php endif; ?>
  <?= !empty($term['is_default'])
      ? View::e(View::t('index.version.default'))
      : View::e(View::t('index.version.club', (int) $term['version'])) ?>
  <?php if ($club !== null): ?>
    · <?= View::e((string) $club['name']) ?>
  <?php endif; ?>
</p>

<?php if (($term['estimated_note'] ?? null) !== null): ?>
  <p class="alert" role="note">
    <strong><?= View::e(View::t('index.estimated')) ?>:</strong>
    <?= View::e((string) $term['estimated_note']) ?>
  </p>
<?php endif; ?>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('index.field.definition')) ?></dt>
    <dd><?= nl2br(View::e((string) $term['definition'])) ?></dd>

    <?php foreach ([
        'formula'        => 'index.field.formula',
        'example'        => 'index.field.example',
        'interpretation' => 'index.field.interpretation',
        'source'         => 'index.field.source',
    ] as $pole => $klucz): ?>
      <?php if (($term[$pole] ?? null) !== null && $term[$pole] !== ''): ?>
        <dt><?= View::e(View::t($klucz)) ?></dt>
        <dd><?= nl2br(View::e((string) $term[$pole])) ?></dd>
      <?php endif; ?>
    <?php endforeach; ?>
  </dl>
</section>

<?php if ($systemowe !== null): ?>
  <?php /*
    PODGLĄD WERSJI SYSTEMOWEJ przy nadpisaniu. Bez niego nie da się porównać
    własnej metodyki z metodyką produktu, a to jest jedyny powód, dla którego
    ktoś nadpisuje hasło: bo chce czegoś innego niż domyślne. Trzeba widzieć,
    od czego się odchodzi.
  */ ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('index.override.system')) ?></h2>
    <p class="hint"><?= View::e(View::t('index.override.hint')) ?></p>
    <dl class="facts">
      <dt><?= View::e(View::t('index.col.name')) ?></dt>
      <dd><?= View::e((string) $systemowe['name']) ?></dd>
      <dt><?= View::e(View::t('index.field.definition')) ?></dt>
      <dd><?= nl2br(View::e((string) $systemowe['definition'])) ?></dd>
    </dl>
  </section>
<?php endif; ?>

<div class="actions">
  <?php if (Users::can($user, 'index') && $club !== null): ?>
    <a class="btn btn--ghost"
       href="/indeks/<?= View::e((string) $term['slug']) ?>/edytuj?klub=<?= (int) $club['id'] ?>">
      <?= View::e(View::t('index.edit')) ?>
    </a>

    <?php /*
      USUNIĘCIE dotyczy WYŁĄCZNIE wersji klubowej — hasła systemowego nie da się
      skasować, bo nie leży w tabeli, tylko w kodzie. Przy nadpisaniu usunięcie
      przywraca treść systemową, przy haśle własnym usuwa je z indeksu.
    */ ?>
    <?php if (empty($term['is_default'])): ?>
      <form class="inline" method="post"
            action="/indeks/<?= View::e((string) $term['slug']) ?>/usun?klub=<?= (int) $club['id'] ?>">
        <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <button class="btn btn--ghost" type="submit">
          <?= View::e(View::t(!empty($term['overrides_default'])
              ? 'index.delete.restore'
              : 'index.delete')) ?>
        </button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  <a class="link" href="/indeks<?= $club !== null ? '?klub=' . (int) $club['id'] : '' ?>">
    <?= View::e(View::t('common.back')) ?>
  </a>
</div>
