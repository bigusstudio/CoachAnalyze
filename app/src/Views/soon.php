<?php
declare(strict_types=1);

use CoachAnalyze\View;

/**
 * Strona zapowiedzi dla części panelu, które powstają w kolejnych etapach.
 * Lepsza niż 404: mówi, że funkcja istnieje w planie, a nie że adres jest zły.
 *
 * @var string|null $heading
 * @var string|null $body
 */
?>
<h1 class="h1"><?= View::e($heading ?? View::t('soon.title')) ?></h1>

<section class="panel">
  <p class="empty"><?= View::e($body ?? View::t('soon.body')) ?></p>
  <p><a class="link" href="/"><?= View::e(View::t('common.back')) ?></a></p>
</section>
