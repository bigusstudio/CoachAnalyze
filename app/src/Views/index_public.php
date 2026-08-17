<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Hasło indeksu dla czytelnika raportu publicznego — BEZ nawigacji panelu.
 *
 * Strona celowo nie zdradza niczego poza treścią hasła: żadnych odnośników
 * do panelu, list haseł ani nazw klubów. Czytelnik przyszedł z raportu
 * po definicję wskaźnika i dokładnie to dostaje.
 *
 * @var array<string,mixed> $term
 */
?>
<main class="auth">
  <article class="auth__box auth__box--wide">
    <h1 class="auth__title"><?= View::e((string) $term['name']) ?></h1>
    <p class="auth__subtitle"><?= View::e(View::t('index.public.subtitle')) ?></p>

    <?php if (($term['estimated_note'] ?? null) !== null): ?>
      <p class="alert" role="note">
        <strong><?= View::e(View::t('index.estimated')) ?>:</strong>
        <?= View::e((string) $term['estimated_note']) ?>
      </p>
    <?php endif; ?>

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
  </article>
</main>
