<?php
declare(strict_types=1);

use CoachAnalyze\Clubs;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Formularz klubu — nowy albo edycja.
 *
 * @var array<string,mixed>|null $club
 * @var string|null $error
 * @var string|null $notice
 * @var string|null $suggestedName  nazwa zaproponowana z danych eksportu
 * @var string|null $backTo
 */
$isNew    = $club === null;
$name     = (string) ($club['name'] ?? $suggestedName ?? '');
$short    = (string) ($club['short_name'] ?? '');
$primary  = View::color($club['color_primary'] ?? null, '#E8722C');
$second   = $club['color_secondary'] ?? null;
$aliases  = implode("\n", Clubs::decodeAliases($club['aliases_json'] ?? null));
if ($isNew && $suggestedName !== null && $aliases === '') {
    $aliases = $suggestedName;
}
?>
<h1 class="h1"><?= View::e(View::t($isNew ? 'club.new' : 'club.edit_title', $name)) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($isNew && $suggestedName !== null): ?>
  <p class="notice"><?= View::e(View::t('club.suggested', $suggestedName)) ?></p>
<?php endif; ?>

<section class="panel">
  <form method="post" action="<?= $isNew ? '/kluby' : '/kluby/' . (int) $club['id'] ?>"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
    <?php if (!empty($backTo)): ?>
      <input type="hidden" name="powrot" value="<?= View::e($backTo) ?>">
    <?php endif; ?>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('club.name')) ?></span>
      <input class="field__input" type="text" name="name" required maxlength="160"
             value="<?= View::e($name) ?>">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('club.short')) ?></span>
      <input class="field__input" type="text" name="short_name" maxlength="32"
             value="<?= View::e($short) ?>">
      <span class="hint"><?= View::e(View::t('club.short.hint')) ?></span>
    </label>

    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('club.color_primary')) ?></span>
        <input class="field__input field__color" type="color" name="color_primary"
               value="<?= View::e($primary) ?>">
      </label>

      <label class="field">
        <span class="field__label"><?= View::e(View::t('club.color_secondary')) ?></span>
        <input class="field__input field__color" type="color" name="color_secondary"
               value="<?= View::e(View::color($second, '#2C6FE8')) ?>">
      </label>
    </div>

    <label class="field field--check">
      <input type="checkbox" name="contrast_fix" value="1" checked>
      <span><?= View::e(View::t('club.contrast')) ?></span>
    </label>
    <p class="hint"><?= View::e(View::t('club.contrast.hint')) ?></p>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('club.aliases')) ?></span>
      <textarea class="field__input" name="aliases" rows="3"><?= View::e($aliases) ?></textarea>
      <span class="hint"><?= View::e(View::t('club.aliases.hint')) ?></span>
    </label>

    <label class="field field--check">
      <input type="checkbox" name="is_own_team" value="1"
             <?= !empty($club['is_own_team']) ? 'checked' : '' ?>>
      <span><?= View::e(View::t('club.own_team')) ?></span>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('club.crest')) ?></span>
      <input class="field__input" type="file" name="crest" accept=".png,.svg">
      <span class="hint"><?= View::e(View::t('club.crest.hint')) ?></span>
    </label>

    <?php if (!$isNew && !empty($club['crest_path'])): ?>
      <p><img class="crest crest--lg" src="/herb/<?= (int) $club['id'] ?>" alt=""></p>
    <?php endif; ?>

    <button class="btn" type="submit"><?= View::e(View::t($isNew ? 'club.create' : 'club.save')) ?></button>
  </form>
</section>

<?php if (!$isNew): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('club.key')) ?></h2>
    <p><code class="key"><?= View::e((string) $club['club_key']) ?></code></p>
    <p class="hint"><?= View::e(View::t('club.key.hint')) ?></p>
  </section>

  <div class="actions">
    <?php // Potwierdzenie renderuje serwer, nie `confirm()` — panel nie ma ani
          // jednego skryptu i usuwanie klubu nie jest powodem, żeby to zmieniać. ?>
    <form method="post" action="/kluby/<?= (int) $club['id'] ?>/usun">
      <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
      <button class="btn btn--ghost" type="submit"><?= View::e(View::t('club.delete')) ?></button>
    </form>
    <a class="link" href="/kluby"><?= View::e(View::t('common.back')) ?></a>
  </div>
<?php else: ?>
  <p><a class="link" href="/kluby"><?= View::e(View::t('common.back')) ?></a></p>
<?php endif; ?>
