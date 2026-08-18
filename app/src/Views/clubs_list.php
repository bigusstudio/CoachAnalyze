<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Lista klubów — punkt wejścia do panelu (Sesja 2).
 *
 * WYŁĄCZNIE TENANCI (`Clubs::tenants()`, `is_own_team = 1`). Rywale nie są
 * „klubem do wyboru" tutaj — żyją w warstwie danych jako strona meczu i mają
 * własny, nieskracany dostęp przez `/kluby/{id}` (link z pokrycia importu).
 *
 * @var list<array<string,mixed>> $clubs
 * @var string|null $notice
 * @var string|null $error
 */
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('club.list')) ?></h1>
  <a class="btn btn--ghost" href="/kluby/nowy?tenant=1"><?= View::e(View::t('club.new')) ?></a>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($clubs === []): ?>
  <p class="empty"><?= View::e(View::t('club.empty')) ?></p>
<?php else: ?>
  <div class="club-cards">
    <?php foreach ($clubs as $c): ?>
      <a class="club-card" href="/klub/<?= (int) $c['id'] ?>">
        <div class="club-card__head">
          <?php if (!empty($c['crest_path'])): ?>
            <?php // Herb przez <img>, nigdy przez wklejenie XML-a do strony:
                  // w <img> skrypty w SVG się nie wykonują. ?>
            <img class="crest" src="/herb/<?= (int) $c['id'] ?>" alt="">
          <?php else: ?>
            <span class="crest crest--empty" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="club-card__name"><?= View::e((string) $c['name']) ?></span>
        </div>

        <div class="club-card__counts">
          <span><?= View::e(View::t('club.hub.count_matches', (int) ($c['matches_count'] ?? 0))) ?></span>
          <span><?= View::e(View::t('club.hub.count_reports', (int) ($c['reports_count'] ?? 0))) ?></span>
        </div>

        <div class="club-card__foot">
          <?php // Klucz publiczny — stoi w adresach `/r/{club_key}/…`, warto go
                // widzieć bez wchodzenia w edycję (CLAUDE.md D3: club_key stały). ?>
          <code><?= View::e((string) $c['club_key']) ?></code>
          <span class="swatch" style="--probka: <?= View::e(View::color($c['color_primary'])) ?>"></span>
          <?php if (!empty($c['color_secondary'])): ?>
            <span class="swatch" style="--probka: <?= View::e(View::color($c['color_secondary'])) ?>"></span>
          <?php endif; ?>
        </div>

        <span class="club-card__note">
          <?= View::e($c['last_import_at'] !== null
              ? View::t('club.card.last_import', substr((string) $c['last_import_at'], 0, 10))
              : View::t('club.card.no_import')) ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
