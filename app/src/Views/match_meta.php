<?php
declare(strict_types=1);

use CoachAnalyze\Session;
use CoachAnalyze\View;

/**
 * Meta meczu — krok przed diffem i generowaniem (Sesja 6 pkt 1).
 *
 * PRZECIWNIK Z LISTY, NIE Z WOLNEGO TEKSTU. Wiersz w `clubs` niesie herb,
 * barwy i `aliases_json` — nazwy, pod jakimi klub występuje w eksportach.
 * To one napędzają dopasowanie drużyn przy kolejnych importach.
 *
 * @var array<string,mixed>|null  $club   klub-tenant (nasza drużyna)
 * @var array<string,mixed>       $import
 * @var array<string,mixed>|null  $mecz
 * @var list<array<string,mixed>> $rywale
 * @var list<array<string,mixed>> $seasons
 * @var string $akcja          dokąd idzie formularz
 * @var string $powrot         dokąd wraca odsyłacz „wróć"
 * @var bool   $edycja         tryb poprawiania meczu już zaimportowanego
 * @var int|null $seasonDefault sezon proponowany, gdy mecz go nie ma
 * @var bool   $maRaport       czy dla tego meczu istnieje już raport
 * @var list<array<string,mixed>> $sklad      skład zapisany w bazie (migracja 017)
 * @var list<array<string,mixed>> $propozycja nazwiska z kolumny zawodnika eksportu
 */
$isHome = $mecz['is_home'] ?? null;

/*
 * TEN SAM FORMULARZ, DWA WEJŚCIA — żadnego drugiego ekranu mety.
 *
 *   import  — krok przed diffem, meta nowego meczu (Sesja 6),
 *   edycja  — poprawka po fakcie, z listy meczów albo z huba klubu.
 *
 * Różni je adres zapisu, dokąd się wraca i jedno zdanie o raporcie. Pola są
 * te same, bo opisują ten sam byt; drugi ekran znaczyłby dwa miejsca, w których
 * da się ustawić datę meczu, i dwie okazje, żeby się rozjechały.
 */
$edycja = $edycja ?? false;
$akcja  = $akcja  ?? '/import/' . (int) ($import['id'] ?? 0) . '/meta';
$powrot = $powrot ?? '/import/' . (int) ($import['id'] ?? 0);
$maRaport = $maRaport ?? false;

// Sezon zaznaczony: wybór operatora, a gdy go nie ma — podpowiedź z kontrolera.
$sezonWybrany = $mecz['season_id'] ?? null;
$sezonWybrany = $sezonWybrany !== null ? (int) $sezonWybrany : ($seasonDefault ?? null);
?>
<h1 class="h1"><?= View::e(View::t($edycja ? 'meta.edit.title' : 'meta.title')) ?></h1>

<?php if (!empty($notice)): ?>
  <p class="notice" role="status"><?= View::e($notice) ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <p class="alert" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>

<section class="panel">
  <p class="hint"><?= View::e(View::t($edycja ? 'meta.edit.lead' : 'meta.lead')) ?></p>

  <?php if ($edycja && $maRaport): ?>
    <?php /*
      UCZCIWA NOTA. Meta nie wchodzi do metryk, więc zmiana daty czy wyniku
      NIE wymaga przeliczania — ale nagłówek gotowego raportu jest już
      wyrenderowany w pliku HTML i zostanie stary do najbliższego „Przelicz".
      Bez tego zdania operator poprawia wynik, otwiera raport i widzi poprzedni.
    */ ?>
    <p class="notice" role="status"><?= View::e(View::t('meta.edit.report_note')) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= View::e($akcja) ?>"
        enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= View::e(Session::csrfToken()) ?>">

    <?= View::render('_meta_pola', [
        'club' => $club, 'mecz' => $mecz, 'rywale' => $rywale,
        'seasons' => $seasons, 'sezonWybrany' => $sezonWybrany,
    ]) ?>

    <?= View::render('_sklad_tabela', ['sklad' => $sklad, 'propozycja' => $propozycja]) ?>

    <button class="btn" type="submit" name="akcja" value="zapisz">
      <?= View::e(View::t($edycja ? 'meta.edit.submit' : 'meta.submit')) ?>
    </button>
  </form>
</section>

<p><a class="link" href="<?= View::e($powrot) ?>"><?= View::e(View::t('common.back')) ?></a></p>
