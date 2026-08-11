<?php
declare(strict_types=1);

use CoachAnalyze\Mappings;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Mapowania klubu: co jest przypisane, co pominięte, kto i kiedy zmieniał.
 *
 * @var array<string,mixed> $club
 * @var array<string,string> $assigned
 * @var list<string> $ignored
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $requests
 * @var list<string> $concepts
 * @var string|null $notice
 * @var string|null $error
 */
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('mapping.club.title')) ?></h1>
  <a class="link" href="/kluby/<?= (int) $club['id'] ?>"><?= View::e(View::t('mapping.club.back')) ?></a>
</div>

<p class="hint"><?= View::e(View::t('mapping.for_club', (string) $club['name'])) ?></p>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<form method="post" action="/kluby/<?= (int) $club['id'] ?>/mapowania">
  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('mapping.club.ignored')) ?></h2>

    <?php /*
      Tagi oznaczone „nie analizuj" NIE WRACAJĄ przy kolejnym imporcie — i o to
      chodziło. Ale decyzja bywa pomyłką albo się dezaktualizuje, więc musi być
      widoczna i odwracalna. Ukryta decyzja jest gorsza od braku decyzji:
      liczby są mniejsze i nikt nie wie dlaczego.
    */ ?>
    <p class="hint"><?= View::e(View::t('mapping.club.ignored.hint')) ?></p>

    <?php if ($ignored === []): ?>
      <p class="empty"><?= View::e(View::t('mapping.club.ignored.empty')) ?></p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('mapping.col.tag')) ?></th>
            <th scope="col"><?= View::e(View::t('mapping.col.concept')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ignored as $tag): ?>
            <tr>
              <td><code class="tag-nazwa"><?= View::e($tag) ?></code></td>
              <td>
                <select class="field__input" name="tag[<?= View::e($tag) ?>]">
                  <option value="<?= View::e(Mappings::NIE_ANALIZUJ) ?>" selected>
                    <?= View::e(View::t('mapping.skip')) ?>
                  </option>
                  <?php foreach ($concepts as $pojecie): ?>
                    <option value="<?= View::e($pojecie) ?>">
                      <?= View::e($pojecie) ?> — <?= View::e(View::t('concept.' . $pojecie)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('mapping.club.assigned')) ?></h2>

    <?php if ($assigned === []): ?>
      <p class="empty"><?= View::e(View::t('mapping.club.assigned.empty')) ?></p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('mapping.col.tag')) ?></th>
            <th scope="col"><?= View::e(View::t('mapping.col.concept')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assigned as $tag => $pojecie): ?>
            <tr>
              <td><code class="tag-nazwa"><?= View::e((string) $tag) ?></code></td>
              <td>
                <select class="field__input" name="tag[<?= View::e((string) $tag) ?>]">
                  <?php foreach ($concepts as $p): ?>
                    <option value="<?= View::e($p) ?>" <?= $p === $pojecie ? 'selected' : '' ?>>
                      <?= View::e($p) ?> — <?= View::e(View::t('concept.' . $p)) ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="<?= View::e(Mappings::NIE_ANALIZUJ) ?>">
                    <?= View::e(View::t('mapping.skip')) ?>
                  </option>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <?php if ($ignored !== [] || $assigned !== []): ?>
    <section class="panel">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('mapping.note')) ?></span>
        <input class="field__input" type="text" name="note" maxlength="500"
               placeholder="<?= View::e(View::t('mapping.note.hint')) ?>">
      </label>
      <p class="hint"><?= View::e(View::t('mapping.versioning')) ?></p>
      <div class="actions">
        <button class="btn" type="submit"><?= View::e(View::t('mapping.club.save')) ?></button>
      </div>
    </section>
  <?php endif; ?>
</form>

<?php if ($requests !== []): ?>
  <section class="panel">
    <h2 class="h2"><?= View::e(View::t('mapping.club.requests')) ?></h2>
    <table class="tbl">
      <thead>
        <tr>
          <th scope="col"><?= View::e(View::t('mapping.col.tag')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.rationale')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.who')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.status')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td><code class="tag-nazwa"><?= View::e((string) $r['tag']) ?></code></td>
            <td><?= !empty($r['rationale'])
                    ? View::e((string) $r['rationale'])
                    : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>
            <td>
              <?= View::e((string) ($r['autor'] ?? '')) ?>
              <span class="hint"><?= View::e(substr((string) $r['created_at'], 0, 16)) ?></span>
            </td>
            <td>
              <span class="tag tag--req-<?= View::e((string) $r['status']) ?>">
                <?= View::e(View::t('mapping.status.' . (string) $r['status'])) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('mapping.club.history')) ?></h2>

  <?php /*
    Mapowanie decyduje o tym, które zdarzenia wchodzą do metryk, więc jest
    zmianą tej samej wagi co zmiana kodu silnika. Pytanie „czemu raport
    z marca pokazywał inną liczbę" musi mieć odpowiedź także tutaj.
  */ ?>
  <?php if ($history === []): ?>
    <p class="empty"><?= View::e(View::t('mapping.club.history.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th scope="col"><?= View::e(View::t('mapping.col.version')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.when')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.who')) ?></th>
          <th scope="col" class="num"><?= View::e(View::t('mapping.col.rules')) ?></th>
          <th scope="col"><?= View::e(View::t('mapping.col.note')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td>v<?= (int) $h['version'] ?></td>
            <td><?= View::e(substr((string) $h['created_at'], 0, 16)) ?></td>
            <td>
              <?= $h['autor'] !== null
                  ? View::e((string) $h['autor'])
                  : '<span class="muted">' . View::e(View::t('mapping.author.system')) . '</span>' ?>
            </td>
            <td class="num"><?= (int) $h['liczba_regul'] ?></td>
            <td><?= !empty($h['note'])
                    ? View::e((string) $h['note'])
                    : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
