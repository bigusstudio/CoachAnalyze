<?php
declare(strict_types=1);

use CoachAnalyze\Auth;
use CoachAnalyze\Users;
use CoachAnalyze\View;

/**
 * Hasło indeksu — pełna treść.
 *
 * @var array<string,mixed> $term
 * @var array<string,mixed>|null $club
 * @var string|null $notice
 */
$user = Auth::currentUser();
?>
<h1 class="h1"><?= View::e((string) $term['name']) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>

<p class="hint">
  <?= View::e(View::t('index.col.concept')) ?>:
  <code class="tag-nazwa"><?= View::e((string) $term['concept']) ?></code>
  ·
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

<div class="actions">
  <?php if (Users::can($user, 'index') && $club !== null): ?>
    <a class="btn btn--ghost"
       href="/indeks/<?= View::e((string) $term['slug']) ?>/edytuj?klub=<?= (int) $club['id'] ?>">
      <?= View::e(View::t('index.edit')) ?>
    </a>
  <?php endif; ?>
  <a class="link" href="/indeks<?= $club !== null ? '?klub=' . (int) $club['id'] : '' ?>">
    <?= View::e(View::t('common.back')) ?>
  </a>
</div>
