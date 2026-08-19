<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Hasło indeksu — edycja istniejącego albo nowe hasło własne klubu.
 *
 * TEN SAM FORMULARZ, DWA TRYBY (`$term === null` = tworzenie). Pola opisują
 * ten sam byt; drugi ekran znaczyłby dwa miejsca, w których powstaje definicja
 * wskaźnika, i dwie okazje, żeby się rozjechały.
 *
 * Edycja zapisuje NOWĄ WERSJĘ klubową — poprzednie zostają, żeby pytanie
 * „czemu raport z marca opisywał ten wskaźnik inaczej" miało odpowiedź.
 *
 * @var array<string,mixed>|null $term   null = tworzenie nowego hasła
 * @var array<string,mixed> $club
 * @var array<string,mixed>|null $draft  treść odesłana po nieudanym zapisie
 * @var string|null $kolizja  slug kolidujący z systemowym, do potwierdzenia
 * @var string|null $error
 */
$nowe    = ($term ?? null) === null;
$draft   = $draft ?? null;
$kolizja = $kolizja ?? null;

/** Wartość pola: z odesłanego szkicu, potem z hasła, na końcu pusta. */
$wartosc = static function (string $pole) use ($draft, $term): string {
    if (is_array($draft) && array_key_exists($pole, $draft)) {
        return (string) $draft[$pole];
    }
    return (string) ($term[$pole] ?? '');
};

$akcja = $nowe
    ? '/indeks/nowe?klub=' . (int) $club['id']
    : '/indeks/' . rawurlencode((string) $term['slug']) . '?klub=' . (int) $club['id'];
?>
<h1 class="h1"><?= View::e($nowe
    ? View::t('index.new.title')
    : View::t('index.edit.title', (string) $term['name'])) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($kolizja !== null): ?>
  <?php /*
    SLUG ZAJĘTY PRZEZ HASŁO SYSTEMOWE. Nadpisanie jest dozwolone i bywa
    zamierzone — klub opisuje wskaźnik własną metodyką — ale nie ma się zdarzyć
    przypadkiem. Dlatego wracamy tu z ostrzeżeniem i jawnym potwierdzeniem
    zamiast zapisać po cichu.
  */ ?>
  <p class="alert" role="alert"><?= View::e(View::t('index.new.collision', $kolizja)) ?></p>
<?php endif; ?>

<p class="hint hint--block"><?= View::e($nowe
    ? View::t('index.new.why', (string) $club['name'])
    : View::t('index.edit.why', (string) $club['name'])) ?></p>

<form method="post" action="<?= View::e($akcja) ?>">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <section class="panel">
    <?php if ($nowe): ?>
      <label class="field">
        <span class="field__label"><?= View::e(View::t('index.field.slug')) ?></span>
        <input class="field__input" type="text" name="slug" maxlength="60"
               value="<?= View::e($wartosc('slug')) ?>">
        <span class="hint"><?= View::e(View::t('index.field.slug.hint')) ?></span>
      </label>

      <label class="field">
        <span class="field__label"><?= View::e(View::t('index.col.concept')) ?></span>
        <input class="field__input" type="text" name="concept" maxlength="40"
               value="<?= View::e($wartosc('concept')) ?>">
        <span class="hint"><?= View::e(View::t('index.field.concept.hint')) ?></span>
      </label>

      <?php if ($kolizja !== null): ?>
        <label class="field field--check">
          <input type="checkbox" name="potwierdzam_nadpisanie" value="1">
          <span><?= View::e(View::t('index.new.collision.confirm')) ?></span>
        </label>
      <?php endif; ?>
    <?php else: ?>
      <p class="hint">
        <?= View::e(View::t('index.col.concept')) ?>:
        <code class="tag-nazwa"><?= View::e((string) $term['concept']) ?></code>
        — <?= View::e(View::t('index.edit.concept_fixed')) ?>
      </p>
    <?php endif; ?>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.col.name')) ?></span>
      <input class="field__input" type="text" name="name" required maxlength="120"
             value="<?= View::e($wartosc('name')) ?>">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.definition')) ?></span>
      <textarea class="field__input" name="definition" rows="3" required><?=
        View::e($wartosc('definition')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.formula')) ?></span>
      <textarea class="field__input" name="formula" rows="2"><?=
        View::e($wartosc('formula')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.example')) ?></span>
      <textarea class="field__input" name="example" rows="2"><?=
        View::e($wartosc('example')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.interpretation')) ?></span>
      <textarea class="field__input" name="interpretation" rows="3"><?=
        View::e($wartosc('interpretation')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.source')) ?></span>
      <textarea class="field__input" name="source" rows="2"><?=
        View::e($wartosc('source')) ?></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('index.field.estimated_note')) ?></span>
      <textarea class="field__input" name="estimated_note" rows="3"
                placeholder="<?= View::e(View::t('index.field.estimated_note.hint')) ?>"><?=
        View::e($wartosc('estimated_note')) ?></textarea>
    </label>

    <p class="hint"><?= View::e(View::t('index.edit.versioning')) ?></p>

    <div class="actions">
      <button class="btn" type="submit"><?= View::e(View::t('index.edit.submit')) ?></button>
      <a class="link" href="<?= View::e($nowe
          ? '/indeks?klub=' . (int) $club['id']
          : '/indeks/' . rawurlencode((string) $term['slug']) . '?klub=' . (int) $club['id']) ?>">
        <?= View::e(View::t('common.back')) ?>
      </a>
    </div>
  </section>
</form>
