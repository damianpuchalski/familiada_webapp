<?php
/**
 * Shared site footer — a trimmed-down reuse of the OWL Web Design footer from
 * owl-it.pl/owl-web-design (source: websites/owl-it/owl-web-design/includes/footer.php),
 * kept to just the badge + copyright row.
 *
 * Callers may set $footerAssetPrefix before including (e.g. '../' for pages
 * nested under /admin). Defaults to '' (page at the web root).
 *
 * @var string|null $footerAssetPrefix
 */
declare(strict_types=1);
$footerAssetPrefix = $footerAssetPrefix ?? '';
?>
<footer class="site-footer">
  <div class="wrap mono site-footer__bottom-inner">
    <span class="site-footer__badge">
      <span class="brand__dots">
        <span class="brand__dot"></span>
        <span class="brand__dot"></span>
        <span class="brand__dot"></span>
      </span>
      <span class="brand__mark"><img src="<?= htmlspecialchars($footerAssetPrefix, ENT_QUOTES) ?>assets/img/owl-mark-white.png" alt=""></span>
    </span>
    <span>© 2026 OWL Web Design · część The OWL Network</span>
  </div>
</footer>
