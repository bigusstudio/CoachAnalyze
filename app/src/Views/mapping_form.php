<?php
declare(strict_types=1);

use CoachAnalyze\Mappings;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Kreator mapowań: tagi z eksportu, o których profil klubu jeszcze nie wie.
 *
 * @var array<string,mixed> $import
 * @var array<string,mixed>|null $club
 * @var list<array<string,mixed>> $tags
 * @var list<array<string,mixed>> $labels
 * @var list<string> $concepts
 * @var list<string> $qualifiers
 * @var string|null $error
 */
?>
<h1 class="h1"><?= View::e(View::t('mapping.title')) ?></h1>

<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php /*
  Wyjaśnienie POWODU, nie opis ekranu. Operator musi wiedzieć, czemu go
  zatrzymaliśmy — inaczej potraktuje to jak formalność i przeklika.
*/ ?>
<p class="hint hint--block"><?= View::e(View::t('mapping.why')) ?></p>

<?php if ($club === null): ?>
  <p class="alert" role="alert"><?= View::e(View::t('mapping.err.no_club')) ?></p>
<?php else: ?>
  <p class="hint"><?= View::e(View::t('mapping.for_club', (string) $club['name'])) ?></p>
<?php endif; ?>

<form method="post" action="/import/<?= (int) $import['id'] ?>/mapowanie">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <?php if ($tags !== []): ?>
    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('mapping.tags')) ?></h2>

      <table class="tbl">
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('mapping.col.tag')) ?></th>
            <th scope="col" class="num"><?= View::e(View::t('mapping.col.count')) ?></th>
            <th scope="col"><?= View::e(View::t('mapping.col.labels')) ?></th>
            <th scope="col"><?= View::e(View::t('mapping.col.concept')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tags as $t): ?>
            <?php $sugestia = $t['suggestion'] ?? ['concept' => null, 'reason' => null]; ?>
            <tr>
              <td><code class="tag-nazwa"><?= View::e((string) $t['name']) ?></code></td>

              <td class="num">
                <?php /*
                  „—" znaczy NIE WIEMY, a nie zero. Liczby wystąpień nie ma dziś
                  w wyniku `inspect` (docs/KONTRAKT_CLI.md); podstawienie zera
                  byłoby wymyśloną daną, a operator decydowałby na jej podstawie.
                */ ?>
                <?php if ($t['count'] === null): ?>
                  <span class="muted" title="<?= View::e(View::t('mapping.count.unknown')) ?>">
                    <?= View::e(View::t('common.dash')) ?>
                  </span>
                <?php else: ?>
                  <?= (int) $t['count'] ?>
                <?php endif; ?>
              </td>

              <td>
                <?php if (($t['labels'] ?? []) === []): ?>
                  <span class="muted"><?= View::e(View::t('common.dash')) ?></span>
                <?php else: ?>
                  <span class="hint"><?= View::e(implode(', ', array_slice((array) $t['labels'], 0, 6))) ?></span>
                <?php endif; ?>
              </td>

              <td>
                <?php /*
                  ZAMKNIĘTA LISTA. Operator przypisuje do istniejącego pojęcia,
                  nie tworzy nowego — rozrost listy oznacza, że model zaczyna
                  kopiować format LiveTag zamiast go tłumaczyć.
                */ ?>
                <select class="field__input" name="tag[<?= View::e((string) $t['name']) ?>]">
                  <option value="<?= View::e(Mappings::NIE_ANALIZUJ) ?>"
                          <?= $sugestia['concept'] === null ? 'selected' : '' ?>>
                    <?= View::e(View::t('mapping.skip')) ?>
                  </option>

                  <?php foreach ($concepts as $pojecie): ?>
                    <option value="<?= View::e($pojecie) ?>"
                            <?= $sugestia['concept'] === $pojecie ? 'selected' : '' ?>>
                      <?= View::e($pojecie) ?> — <?= View::e(View::t('concept.' . $pojecie)) ?>
                    </option>
                  <?php endforeach; ?>

                  <option value="<?= View::e(Mappings::ZGLOS) ?>">
                    <?= View::e(View::t('mapping.report_missing')) ?>
                  </option>
                </select>

                <?php if (!empty($sugestia['reason'])): ?>
                  <?php /*
                    Podpowiedź jest SUGESTIĄ, nie automatem: zaznaczamy ją
                    wstępnie, ale zatwierdza człowiek. Automatyczne przypisanie
                    byłoby zgadywaniem pojęcia, a to zmienia liczby w raporcie.
                  */ ?>
                  <p class="hint hint--block">
                    <?= View::e(View::t('mapping.suggested', (string) $sugestia['reason'])) ?>
                  </p>
                <?php endif; ?>

                <label class="field field--slim">
                  <span class="field__label"><?= View::e(View::t('mapping.rationale')) ?></span>
                  <input class="field__input" type="text"
                         name="rationale[<?= View::e((string) $t['name']) ?>]"
                         placeholder="<?= View::e(View::t('mapping.rationale.hint')) ?>">
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <?php if ($labels !== []): ?>
    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('mapping.labels')) ?></h2>
      <?php /*
        Etykiety mapujemy na KWALIFIKATORY, nie na pojęcia. Nowa etykieta
        w eksporcie uszczegóławia zdarzenie, a nie wprowadza nowego ich rodzaju.
      */ ?>
      <p class="hint"><?= View::e(View::t('mapping.labels.hint')) ?></p>

      <table class="tbl">
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('mapping.col.label')) ?></th>
            <th scope="col" class="num"><?= View::e(View::t('mapping.col.count')) ?></th>
            <th scope="col"><?= View::e(View::t('mapping.col.qualifier')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($labels as $l): ?>
            <tr>
              <td><code class="tag-nazwa"><?= View::e((string) $l['name']) ?></code></td>
              <td class="num">
                <?= $l['count'] === null
                    ? '<span class="muted">' . View::e(View::t('common.dash')) . '</span>'
                    : (int) $l['count'] ?>
              </td>
              <td>
                <select class="field__input" name="label[<?= View::e((string) $l['name']) ?>]">
                  <option value="<?= View::e(Mappings::NIE_ANALIZUJ) ?>" selected>
                    <?= View::e(View::t('mapping.skip')) ?>
                  </option>
                  <?php foreach ($qualifiers as $kw): ?>
                    <option value="<?= View::e($kw) ?>"><?= View::e($kw) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <section class="panel">
    <label class="field">
      <span class="field__label"><?= View::e(View::t('mapping.note')) ?></span>
      <input class="field__input" type="text" name="note" maxlength="500"
             placeholder="<?= View::e(View::t('mapping.note.hint')) ?>">
    </label>

    <?php /*
      Zapis tworzy NOWĄ WERSJĘ profilu; poprzednie zostają. Mówimy o tym wprost,
      bo to zmiana wpływająca na liczby we wszystkich kolejnych raportach klubu.
    */ ?>
    <p class="hint"><?= View::e(View::t('mapping.versioning')) ?></p>

    <div class="actions">
      <button class="btn" type="submit"><?= View::e(View::t('mapping.submit')) ?></button>
      <a class="link" href="/import/<?= (int) $import['id'] ?>">
        <?= View::e(View::t('common.back')) ?>
      </a>
    </div>
  </section>
</form>
