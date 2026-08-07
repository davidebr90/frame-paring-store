<?php
// Selettore di lingua: menu a tendina con bandiera e nome nella lingua
// stessa. <details> invece di JavaScript: funziona sempre, anche se uno
// script fallisce, e resta raggiungibile da tastiera.
$here = basename(strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '?'));
$carry = $_GET;
unset($carry['lang']);
?>
<details class="lang-select<?= isset($langSelectClass) ? ' ' . $langSelectClass : '' ?>">
  <summary aria-label="<?= e(t('chrome.language_title')) ?>">
    <span class="lang-flag"><?= lang_flag(lang()) ?></span>
    <span class="lang-name"><?= e(LANGUAGES[lang()]['label']) ?></span>
    <svg class="lang-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M6 9l6 6 6-6"/>
    </svg>
  </summary>
  <div class="lang-menu" role="menu">
    <?php foreach (lang_options() as $code => $info):
        $query = ['lang' => $code] + $carry; ?>
    <a role="menuitem" hreflang="<?= e($code) ?>"
       class="lang-item<?= lang() === $code ? ' active' : '' ?>"
       href="<?= e($here . '?' . http_build_query($query)) ?>">
      <span class="lang-flag"><?= lang_flag($code) ?></span>
      <span class="lang-name"><?= e($info['label']) ?></span>
      <span class="lang-code"><?= e($info['region']) ?></span>
      <?php if (lang() === $code): ?>
      <svg class="lang-check" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20 6L9 17l-5-5"/>
      </svg>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</details>
