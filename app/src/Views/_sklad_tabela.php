<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Tabela składu — WSPÓLNA dla formularza importu i zakładki „Skład" karty meczu.
 *
 * @var list<array<string,mixed>> $sklad
 * @var list<array<string,mixed>> $propozycja
 */
?>
    <?php /* ------------------------------------------------ skład meczu (Sesja 6) */ ?>
    <h2 class="h2"><?= View::e(View::t('roster.title')) ?></h2>
    <p class="hint"><?= View::e(View::t('roster.hint')) ?></p>

    <?php if ($propozycja !== []): ?>
      <?php /*
        PROPOZYCJA, NIE ZAPIS. Eksport niesie wyłącznie tych, którzy dostali
        taga, i nie wie nic o minutach ani numerach — zapisany bez pytania
        udawałby skład, którym nie jest. Przycisk wypełnia puste wiersze
        i wymaga ponownego zapisu, więc decyzja zostaje po stronie operatora.
      */ ?>
      <p class="notice" role="status">
        <?= View::e(View::t('roster.from_export', count($propozycja))) ?>
        <button class="btn s" type="submit" name="akcja" value="z_eksportu">
          <?= View::e(View::t('roster.from_export.submit')) ?>
        </button>
      </p>
    <?php endif; ?>

    <div class="tbl-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th><?= View::e(View::t('roster.col.player')) ?></th>
            <th><?= View::e(View::t('roster.col.number')) ?></th>
            <th><?= View::e(View::t('roster.col.position')) ?></th>
            <th><?= View::e(View::t('roster.col.minutes')) ?></th>
            <th><?= View::e(View::t('roster.col.starter')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php for ($i = 0; $i < \CoachAnalyze\Roster::WIERSZY; $i++): ?>
          <?php $z = $sklad[$i] ?? []; ?>
          <tr>
            <td>
              <input class="field__input" type="text" maxlength="160"
                     name="sklad[<?= $i ?>][player]"
                     value="<?= View::e((string) ($z['player'] ?? '')) ?>">
            </td>
            <td>
              <input class="field__input" type="number" min="1" max="999"
                     name="sklad[<?= $i ?>][number]"
                     value="<?= $z['number'] ?? '' ?>">
            </td>
            <td>
              <input class="field__input" type="text" maxlength="16"
                     name="sklad[<?= $i ?>][position]"
                     value="<?= View::e((string) ($z['position'] ?? '')) ?>">
            </td>
            <td>
              <input class="field__input" type="number" min="0"
                     max="<?= \CoachAnalyze\Roster::MAX_MINUT ?>"
                     name="sklad[<?= $i ?>][minutes]"
                     value="<?= $z['minutes'] ?? '' ?>">
            </td>
            <td>
              <input type="checkbox" name="sklad[<?= $i ?>][is_starter]" value="1"
                     <?= !empty($z['is_starter']) ? 'checked' : '' ?>>
            </td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= View::e(View::t('roster.empty_row')) ?></p>

