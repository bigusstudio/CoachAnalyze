<?php
declare(strict_types=1);

use CoachAnalyze\Notes;
use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Notatnik: wyszukiwanie, lista, dodawanie.
 *
 * @var list<array<string,mixed>> $notes
 * @var array<string,int> $tags
 * @var array<string,mixed> $filtr
 * @var list<array<string,mixed>> $matches
 * @var list<array<string,mixed>> $clubs
 * @var string|null $notice
 */
?>
<h1 class="h1"><?= View::e(View::t('note.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <form class="filtr" method="get" action="/notatki">
    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.search')) ?></span>
      <input class="field__input" type="search" name="q" value="<?= View::e((string) ($filtr['q'] ?? '')) ?>"
             placeholder="<?= View::e(View::t('note.search.hint')) ?>">
    </label>
    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.scope')) ?></span>
      <select class="field__input" name="poziom">
        <option value=""><?= View::e(View::t('matches.all')) ?></option>
        <?php foreach (Notes::SCOPES as $s): ?>
          <option value="<?= View::e($s) ?>" <?= ($filtr['poziom'] ?? '') === $s ? 'selected' : '' ?>>
            <?= View::e(View::t('note.scope.' . $s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.tag')) ?></span>
      <input class="field__input" type="text" name="tag" value="<?= View::e((string) ($filtr['tag'] ?? '')) ?>">
    </label>
    <button class="btn" type="submit"><?= View::e(View::t('matches.filter')) ?></button>
  </form>

  <?php if ($tags !== []): ?>
    <p class="hint">
      <?= View::e(View::t('note.tags_used')) ?>
      <?php foreach (array_slice($tags, 0, 12, true) as $tag => $ile): ?>
        <a class="tag tag--link" href="/notatki?tag=<?= rawurlencode((string) $tag) ?>">
          <?= View::e((string) $tag) ?> <span class="muted"><?= (int) $ile ?></span>
        </a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('note.found', count($notes))) ?></h2>

  <?php if ($notes === []): ?>
    <p class="empty"><?= View::e(View::t(
        ($filtr['q'] ?? '') !== '' || ($filtr['tag'] ?? '') !== ''
            ? 'note.empty_search'
            : 'note.empty')) ?></p>
  <?php else: ?>
    <ul class="notatki">
      <?php foreach ($notes as $n): ?>
        <li class="notatka">
          <div class="notatka__glowa">
            <span class="tag tag--<?= $n['scope'] === 'event' ? 'running' : 'queued' ?>">
              <?= View::e(View::t('note.scope.' . $n['scope'])) ?>
            </span>
            <?php if (!empty($n['title'])): ?>
              <strong><?= View::e((string) $n['title']) ?></strong>
            <?php endif; ?>
            <?php if (!empty($n['event_ref'])): ?>
              <?php // Odniesienie do konkretnego zagrania w raporcie. ?>
              <code class="ref"><?= View::e((string) $n['event_ref']) ?></code>
            <?php endif; ?>
            <span class="muted"><?= View::e(substr((string) $n['created_at'], 0, 16)) ?></span>
          </div>

          <div class="notatka__tresc"><?= nl2br(View::e((string) $n['body'])) ?></div>

          <?php if ($n['tags'] !== []): ?>
            <div class="notatka__tagi">
              <?php foreach ($n['tags'] as $tag): ?>
                <a class="tag tag--link" href="/notatki?tag=<?= rawurlencode((string) $tag) ?>">
                  <?= View::e((string) $tag) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" action="/notatki/<?= (int) $n['id'] ?>/usun">
            <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <button class="link link--btn" type="submit"><?= View::e(View::t('note.delete')) ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="h2"><?= View::e(View::t('note.new')) ?></h2>

  <form method="post" action="/notatki">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('note.scope')) ?></span>
        <select class="field__input" name="scope">
          <?php foreach (Notes::SCOPES as $s): ?>
            <option value="<?= View::e($s) ?>"><?= View::e(View::t('note.scope.' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= View::e(View::t('note.category')) ?></span>
        <select class="field__input" name="kategoria">
          <?php foreach (Notes::CATEGORIES as $k): ?>
            <option value="<?= View::e($k) ?>"><?= View::e($k) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="grid2">
      <label class="field">
        <span class="field__label"><?= View::e(View::t('note.match')) ?></span>
        <select class="field__input" name="match_id">
          <option value=""><?= View::e(View::t('common.dash')) ?></option>
          <?php foreach ($matches as $m): ?>
            <option value="<?= (int) $m['id'] ?>">
              <?= View::e(substr((string) ($m['played_at'] ?? '—'), 0, 10)) ?>
              — <?= View::e((string) ($m['home_name'] ?? View::t('match.no_club'))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= View::e(View::t('note.club')) ?></span>
        <select class="field__input" name="club_id">
          <option value=""><?= View::e(View::t('common.dash')) ?></option>
          <?php foreach ($clubs as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= View::e((string) $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.event_ref')) ?></span>
      <input class="field__input" type="text" name="event_ref" maxlength="64"
             placeholder="<?= View::e(View::t('note.event_ref.hint')) ?>">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.note_title')) ?></span>
      <input class="field__input" type="text" name="title" maxlength="200">
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.body')) ?></span>
      <textarea class="field__input" name="body" rows="4" required></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::e(View::t('note.tags')) ?></span>
      <input class="field__input" type="text" name="tags"
             placeholder="<?= View::e(View::t('note.tags.hint')) ?>">
    </label>

    <button class="btn" type="submit"><?= View::e(View::t('note.create')) ?></button>
  </form>
</section>
