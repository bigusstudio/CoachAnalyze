<?php
declare(strict_types=1);

use CoachAnalyze\Clubs;
use CoachAnalyze\View;

/**
 * Lista klubów.
 *
 * @var list<array<string,mixed>> $clubs
 * @var string|null $notice
 * @var string|null $error
 */
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('club.list')) ?></h1>
  <a class="btn btn--ghost" href="/kluby/nowy"><?= View::e(View::t('club.new')) ?></a>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <?php if ($clubs === []): ?>
    <p class="empty"><?= View::e(View::t('club.empty')) ?></p>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th></th>
          <th><?= View::e(View::t('club.name')) ?></th>
          <th><?= View::e(View::t('club.key')) ?></th>
          <th><?= View::e(View::t('club.colors')) ?></th>
          <th><?= View::e(View::t('club.matches')) ?></th>
          <th><?= View::e(View::t('match.action')) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clubs as $c): ?>
        <tr>
          <td>
            <?php if (!empty($c['crest_path'])): ?>
              <?php // Herb przez <img>, nigdy przez wklejenie XML-a do strony:
                    // w <img> skrypty w SVG się nie wykonują. ?>
              <img class="crest" src="/herb/<?= (int) $c['id'] ?>" alt="">
            <?php else: ?>
              <span class="crest crest--empty" aria-hidden="true"></span>
            <?php endif; ?>
          </td>
          <td>
            <?= View::e((string) $c['name']) ?>
            <?php if (!empty($c['is_own_team'])): ?>
              <span class="tag tag--done"><?= View::e(View::t('club.own')) ?></span>
            <?php endif; ?>
            <?php $aliases = Clubs::decodeAliases($c['aliases_json'] ?? null); ?>
            <?php if ($aliases !== []): ?>
              <div class="hint"><?= View::e(View::t('club.aliases_short', implode(', ', $aliases))) ?></div>
            <?php endif; ?>
          </td>
          <td><code><?= View::e((string) $c['club_key']) ?></code></td>
          <td>
            <span class="swatch" style="--probka: <?= View::e(View::color($c['color_primary'])) ?>"></span>
            <?php if (!empty($c['color_secondary'])): ?>
              <span class="swatch" style="--probka: <?= View::e(View::color($c['color_secondary'])) ?>"></span>
            <?php endif; ?>
          </td>
          <td><?= (int) ($c['matches_count'] ?? 0) ?></td>
          <td class="akcje">
            <a class="link" href="/kluby/<?= (int) $c['id'] ?>"><?= View::e(View::t('club.edit')) ?></a>
            <?php /*
              Mapowania sa osobnym ekranem, nie sekcja formularza klubu:
              to decyzje wplywajace na liczby w raporcie, wersjonowane
              i z wlasna historia — a nie pole do poprawienia przy okazji.
            */ ?>
            <a class="link" href="/kluby/<?= (int) $c['id'] ?>/mapowania">
              <?= View::e(View::t('mapping.club.link')) ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
