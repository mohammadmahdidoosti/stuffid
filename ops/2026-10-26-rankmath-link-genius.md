# Rank Math: disable AI Link Genius (2026-10-26)

## Why
Rank Math Pro's AI Link Genius builds its "exclude terms" settings list on every admin request,
loading all 14,005 product categories (about 109k internal events, 10-11 s per admin request).
Code path: link-genius/Admin/class-admin.php:103 -> get_exclude_terms_for_settings -> get_taxonomy_terms -> get_terms.

## State before (Rank Math > Dashboard > Modules)
ON:  AI visibility, Content AI, 404 Monitor, Analytics, Instant Indexing, AI Link Genius, LLMS.txt,
     Local SEO, Marketplace, Redirections, Schema, Role Manager, SEO Analyzer, Sitemap, WooCommerce
OFF: ACF, AMP, bbPress, BuddyPress, Image SEO, News Sitemap, Podcast, Video Sitemap, Web Stories

Link Genius usage found: one link-status audit (2026-10-19, 1,284 links, 36 broken),
no keyword maps, no bulk-update history.

## Baseline timings (before)
admin-ajax empty: 13.2 / 12.4 / 13.4 s | home 6.3 s | product 6.1 s | sitemap_index.xml 31.0 s

## Change
Turn OFF only "AI Link Genius".

## Rollback
Rank Math SEO > Dashboard > Modules > AI Link Genius > switch ON. Settings and audit data are kept by Rank Math.

## After (2026-10-26 ~11:05 Tehran), Link Genius OFF, all other modules unchanged (verified after reload)
admin-ajax empty: 1.9 / 1.6 / 2.2 s | settings page 2.4 s | home 4.8 s | product 4.4 s | sitemap_index.xml 1.3 s
Checks: sitemap index valid (167 entries); product page has title, price and Rank Math SEO meta.

## Notes
- Home page HTML is ~2.5 MB and product page ~2.1 MB: likely the 14,005 categories rendered in menus/filters (separate issue).
- WebiBulki run stopped at 180/396 with a timeout before this change; NOT re-run automatically
  (re-running a percentage change could apply twice to the first 180 products).
- Diagnostic plugins still installed: Query Monitor, HY Init Profiler (inactive without its secret parameter). To be removed by the owner.

## 24h check
Re-measure the same requests on 2026-10-27; confirm module still OFF; ask owner about any new errors.
