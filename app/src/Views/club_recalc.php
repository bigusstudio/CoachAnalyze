<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Zbiorcze przeliczenie raportów klubu pod aktualny templat (Sesja 7).
 *
 * DWIE LISTY OBOK SIEBIE I TO JEST CELOWE:
 *
 *   „Postęp partii"          — co się dzieje TERAZ, mecz po meczu, z błędami,
 *   „Nieaktualne raporty"    — co ZOSTAJE do zrobienia, łącznie z pozycjami,
 *                              których nie da się zakolejkować.
 *
 * Sam pasek postępu ukryłby raport zablokowany brakiem surowych plików: nie
 * wszedł do partii, więc nie ma go w postępie, a jest wciąż nieaktualny.
 *
 * @var array<string,mixed>       $club
 * @var int                       $current   aktualna wersja templatu klubu (0 = brak)
 * @var list<array<string,mixed>> $outdated
 * @var string|null               $batch
 * @var array<string,mixed>|null  $progress
 * @var string|null $notice
 * @var string|null $error
 */
$doKolejki = array_filter($outdated, static fn(array $r) => !empty($r['raw_ready']));
?>
<div class="actions actions--head">
  <h1 class="h1"><?= View::e(View::t('recalc.title')) ?></h1>
  <span class="hint"><?= View::e(View::t('recalc.count', count($outdated))) ?></span>
</div>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<?php if ($current === 0): ?>
  <?php /* Klub bez templatu nie ma do czego przeliczać — mówimy to wprost
           i pokazujemy drogę wyjścia, zamiast pustej tabeli. */ ?>
  <p class="empty"><?= View::e(View::t('recalc.no_template')) ?></p>
  <p>
    <a class="btn" href="/klub/<?= (int) $club['id'] ?>/konfigurator">
      <?= View::e(View::t('club.hub.configure')) ?>
    </a>
  </p>
<?php else: ?>

  <p class="hint"><?= View::e(View::t('recalc.lead')) ?></p>
  <p class="hint"><?= View::e(View::t('recalc.current', $current)) ?></p>

  <?php if ($progress !== null && $progress['total'] > 0): ?>
    <?php /*
      LICZNIK X/N ŻYWY, TABELA Z SERWERA.

      `data-partia-*` włącza odpytywanie w skrypcie: podmienia SAME LICZBY,
      a gdy partia się domknie albo przybędzie błędów — przeładowuje stronę,
      żeby tabelę niżej wyrenderował serwer. Druga implementacja tej tabeli
      w JavaScripcie byłaby drugim miejscem, w którym dane mogą się rozjechać.

      Bez skryptu wszystko działa tak samo, tylko wolniej: odświeża
      `<meta refresh>` z layoutu (patrz `showClubRecalc()`).
    */ ?>
    <section class="panel"
             data-partia="<?= View::e((string) $batch) ?>"
             data-partia-punkt="/partia/<?= View::e((string) $batch) ?>/stan"
             data-partia-trwa="<?= (int) $progress['working'] > 0 ? '1' : '0' ?>"
             data-partia-bledy="<?= (int) $progress['failed'] ?>">
      <h2 class="h2"><?= View::e(View::t('recalc.progress')) ?></h2>

      <p role="status">
        <strong>
          <?= View::e(View::t('recalc.progress.done_label')) ?>
          <b data-rola="gotowe"><?= (int) $progress['done'] ?></b>
          <?= View::e(View::t('recalc.progress.of_total', (int) $progress['total'])) ?>
        </strong>
        <?php if ((int) $progress['working'] > 0): ?>
          · <?= View::e(View::t('recalc.progress.working', (int) $progress['working'])) ?>
        <?php endif; ?>
        <span class="tag tag--older"<?= (int) $progress['failed'] > 0 ? '' : ' hidden' ?>>
          <?= View::e(View::t('recalc.progress.failed_label')) ?>
          <b data-rola="nieudane"><?= (int) $progress['failed'] ?></b>
        </span>
        <?php if ((int) $progress['working'] === 0): ?>
          · <?= View::e(View::t('recalc.progress.done')) ?>
        <?php endif; ?>
      </p>

      <table class="tbl">
        <caption class="sr-only"><?= View::e(View::t('recalc.progress')) ?></caption>
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('recalc.col.match')) ?></th>
            <th scope="col"><?= View::e(View::t('recalc.col.status')) ?></th>
            <th scope="col"><?= View::e(View::t('recalc.col.action')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($progress['rows'] as $poz): ?>
          <tr>
            <td>
              <a class="link" href="/mecze/<?= (int) $poz['match_id'] ?>/historia">
                <?= View::e((string) ($poz['label'] ?? View::t('common.unknown'))) ?>
              </a>
            </td>
            <td>
              <?= View::status((string) $poz['status']) ?>
              <?php if (!empty($poz['error'])): ?>
                <?php /* Powód przy pozycji, nie w zbiorczym komunikacie: przy
                         kilkunastu meczach lista błędów bez przypisania do meczu
                         nie mówi, który plik naprawić. */ ?>
                <br><span class="hint"><?= View::e((string) $poz['error']) ?></span>
              <?php endif; ?>
            </td>
            <td class="akcje">
              <a class="link" href="/zadania/<?= (int) $poz['job_id'] ?>"><?= View::e(View::t('recalc.job')) ?></a>
              <?php if ((int) $poz['report_id'] > 0): ?>
                <a class="link" href="/raport/<?= (int) $poz['report_id'] ?>"><?= View::e(View::t('reports.act.open')) ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <?php if ($outdated === []): ?>
    <p class="empty"><?= View::e(View::t('recalc.none')) ?></p>
  <?php else: ?>
    <section class="panel">
      <h2 class="h2"><?= View::e(View::t('recalc.count', count($outdated))) ?></h2>
      <p class="hint"><?= View::e(View::t('recalc.only_latest')) ?></p>

      <table class="tbl">
        <caption class="sr-only"><?= View::e(View::t('recalc.title')) ?></caption>
        <thead>
          <tr>
            <th scope="col"><?= View::e(View::t('recalc.col.match')) ?></th>
            <th scope="col"><?= View::e(View::t('recalc.col.version')) ?></th>
            <th scope="col"><?= View::e(View::t('recalc.col.generated')) ?></th>
            <th scope="col"><?= View::e(View::t('recalc.col.action')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($outdated as $r): ?>
          <tr>
            <td>
              <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>/historia">
                <?= View::e(trim((string) ($r['home_name'] ?? '')) ?: View::t('common.unknown')) ?>
                —
                <?= View::e(trim((string) ($r['away_name'] ?? '')) ?: View::t('common.unknown')) ?>
              </a>
            </td>
            <td>
              <span class="tag tag--older">
                <?= $r['template_version'] === null
                    ? View::e(View::t('reports.tplv.outdated.none', (int) $r['tpl_current']))
                    : View::e(View::t(
                        'reports.tplv.outdated',
                        (int) $r['template_version'],
                        (int) $r['tpl_current']
                    )) ?>
              </span>
            </td>
            <td><?= View::e(substr((string) $r['generated_at'], 0, 16)) ?></td>
            <td class="akcje">
              <?php if (!empty($r['raw_ready'])): ?>
                <form class="inline" method="post" action="/raport/<?= (int) $r['id'] ?>/przelicz">
                  <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
                  <input type="hidden" name="powrot"
                         value="/klub/<?= (int) $club['id'] ?>/przelicz">
                  <button class="link" type="submit"><?= View::e(View::t('recalc.act')) ?></button>
                </form>
              <?php else: ?>
                <?php /* Brak danych ma być widoczny, nie zamaskowany (CLAUDE.md §8):
                         piszemy powód i od razu drogę wyjścia. */ ?>
                <span class="muted"><?= View::e(View::t('recalc.blocked')) ?></span>
                <a class="link" href="/mecze/<?= (int) $r['match_id'] ?>/wgraj">
                  <?= View::e(View::t('recalc.blocked.act')) ?>
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <?php if ($doKolejki !== []): ?>
      <section class="panel">
        <form method="post" action="/klub/<?= (int) $club['id'] ?>/przelicz">
          <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">
          <button class="btn" type="submit">
            <?= View::e(View::t('recalc.bulk.submit', count($doKolejki))) ?>
          </button>
          <p class="hint"><?= View::e(View::t('recalc.bulk.hint')) ?></p>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<p>
  <a class="link" href="/klub/<?= (int) $club['id'] ?>/raporty"><?= View::e(View::t('nav.reports')) ?></a>
  ·
  <a class="link" href="/klub/<?= (int) $club['id'] ?>"><?= View::e(View::t('common.back')) ?></a>
</p>
