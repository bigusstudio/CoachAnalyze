<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Historia zdarzeń jednego meczu.
 *
 * Odpowiada na pytanie „co się z tym meczem działo i kiedy" — łącznie ze
 * zdarzeniami, których nikt nie klikał, bo wykonał je proces roboczy z crona.
 *
 * @var array<string,mixed> $match
 * @var list<array<string,mixed>> $events
 * @var string|null $notice
 * @var string|null $error
 */
$opis = trim((string) ($match['home_name'] ?? '')) . ' — ' . trim((string) ($match['away_name'] ?? ''));
?>
<?php /*
  KOMUNIKATY MUSZĄ SIĘ TU POKAZAĆ. Historia meczu jest celem powrotu po zapisie
  danych meczu, a widok bez tych dwóch akapitów po prostu gubił potwierdzenie —
  operator zapisywał wynik i nie dostawał żadnej odpowiedzi, łącznie z notą
  o tym, że nagłówek raportu odświeży się dopiero przy „Przelicz".
*/ ?>
<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('history.title')) ?></h1>
  <span>
    <a class="link" href="/mecze/<?= (int) $match['id'] ?>/notatki">
      <?= View::e(View::t('history.notes')) ?>
    </a>
    <?php /*
      WGRANIE EKSPORTU DO ISTNIEJĄCEGO MECZU (Sesja 7). Stoi tutaj, bo to jest
      „widok meczu": droga wyjścia dla raportu, którego nie da się przeliczyć,
      bo surowy eksport zniknął z dysku.
    */ ?>
    <a class="link" href="/mecze/<?= (int) $match['id'] ?>/meta">
      <?= View::e(View::t('meta.edit.link')) ?>
    </a>
    <a class="link" href="/mecze/<?= (int) $match['id'] ?>/wgraj">
      <?= View::e(View::t('reupload.link')) ?>
    </a>
  </span>
</div>

<section class="panel">
  <dl class="facts">
    <dt><?= View::e(View::t('history.match')) ?></dt>
    <dd><?= View::e(trim($opis, ' —') !== '' ? $opis : View::t('common.unknown')) ?></dd>

    <dt><?= View::e(View::t('history.played')) ?></dt>
    <dd><?= $match['played_at'] !== null
            ? View::e((string) $match['played_at'])
            : '<span class="muted">' . View::e(View::t('common.dash')) . '</span>' ?></dd>

    <dt><?= View::e(View::t('history.status')) ?></dt>
    <dd><?= View::status((string) $match['status']) ?></dd>
  </dl>
</section>

<?php if ($events === []): ?>
  <p class="empty"><?= View::e(View::t('history.empty')) ?></p>
<?php else: ?>
  <section class="panel">
    <ol class="os">
      <?php foreach ($events as $z): ?>
        <li class="os__poz os__poz--<?= View::e((string) $z['kind']) ?>">
          <span class="os__czas"><?= View::e(substr((string) $z['at'], 0, 16)) ?></span>

          <span class="os__opis">
            <?php if (!empty($z['url'])): ?>
              <a class="link" href="<?= View::e((string) $z['url']) ?>">
                <?= View::e(View::t('history.kind.' . (string) $z['kind'])) ?>
              </a>
            <?php else: ?>
              <?= View::e(View::t('history.kind.' . (string) $z['kind'])) ?>
            <?php endif; ?>

            <?php if (!empty($z['detail'])): ?>
              <span class="hint"><?= View::e((string) $z['detail']) ?></span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>
