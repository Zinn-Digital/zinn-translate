=== Zinn® Translate ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-translate
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, seo, hreflang, localization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve your site in every language Zinn Digital® has translated it into, each on its own web address, with correct hreflang tags.

== Description ==

Your site is translated by Zinn Digital®. This plugin is what shows those translations to your visitors.

Each language gets its own web address — `/fr/about/` alongside `/about/` — and the right `hreflang` tags, so search engines index every version instead of treating them as duplicates of each other.

= What it does not do =

* **It does not translate.** Not one word is generated on your server. It fetches finished text from Zinn Digital® and renders it.
* **It does not write to your database.** No duplicated posts, no extra post type, no taxonomy. Translations are applied while the page renders, so deactivating the plugin returns your site to exactly what it was, with nothing to clean up.
* **It adds no endpoint and no shortcode.** It makes one cached request to Zinn Digital® every fifteen minutes per language, not one per page view.

= What your visitors see =

A page whose title is translated and whose body is not shows the translated title and your original body. Nothing is ever blanked because a translation is not finished yet — you always see your own words instead.

If Zinn Digital® cannot be reached, your site serves its original content exactly as it always did.

== Installation ==

1. Upload the plugin and activate it.
2. In Zinn Digital®, open the site's Translation tab and copy the Site ID and read token.
3. In WordPress, go to Settings → Zinn® Translate, paste both, and list the languages you want to publish.

== External services ==

This plugin serves your site in the languages Zinn Digital® has translated it into. **It does not
translate anything** — translation happens on Zinn Digital®; this plugin fetches the finished text
and renders it.

**What is sent, and when**

* **Fetching translations (on a schedule and on cache miss).** The plugin requests
  `https://api.zinndigital.com/v1/sites/{site}/…` for the finished translations of this site's
  content, sending its site identifier and the locale being rendered.

**No visitor data is transmitted** — not IP addresses, not the page a visitor requested, not
analytics of any kind. A site that cannot reach Zinn Digital® serves its own original content
unchanged.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

== Translations ==

This plugin serves your site's content in the languages Zinn Digital® has translated it into.
Separately from that, **the plugin's own admin interface is translated into 57 languages** — the
settings screen, every label, notice and error. The two are independent: the interface is in your
language whether or not you have any content translations.

All 20 user-visible strings are complete in every one of the 53 languages WordPress can serve
today:

Amharic (am), Arabic (ar), Azerbaijani (az), Bulgarian (bg_BG), Bengali (Bangladesh)
(bn_BD), Czech (cs_CZ), German (de_DE), Greek (el), Spanish (Spain) (es_ES), Persian
(fa_IR), French (France) (fr_FR), Gujarati (gu), Hebrew (he_IL), Hindi (hi_IN), Croatian
(hr), Hungarian (hu_HU), Armenian (hy), Indonesian (id_ID), Italian (it_IT), Japanese
(ja), Georgian (ka_GE), Kazakh (kk), Khmer (km), Kannada (kn), Korean (ko_KR), Lao (lo),
Malayalam (ml_IN), Mongolian (mn), Marathi (mr), Malay (ms_MY), Myanmar (Burmese)
(my_MM), Nepali (ne_NP), Dutch (nl_NL), Panjabi (India) (pa_IN), Polish (pl_PL), Pashto
(ps), Portuguese (Brazil) (pt_BR), Romanian (ro_RO), Russian (ru_RU), Sinhala (si_LK),
Albanian (sq), Serbian (sr_RS), Swahili (sw), Tamil (ta_IN), Telugu (te), Thai (th),
Tagalog (tl), Turkish (tr_TR), Ukrainian (uk), Urdu (ur), Uzbek (uz_UZ), Vietnamese
(vi), Chinese (China) (zh_CN)

A further 4 ship complete in the plugin — Hausa (ha), Somali (so_SO), Tajik (tg), Yoruba (yo) — but
WordPress core does not currently provide a locale for them, so WordPress cannot load them.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

== Frequently Asked Questions ==

= Do I have to publish all the languages? =

No. Every language is translated — that is included — but you choose which ones your visitors see. Switching one on is instant, because the words are already there.

= Will this slow my site down? =

No. Bundles are cached in your site's object cache, so a page view costs one cache read. If we are unreachable, the plugin gives up in three seconds and serves your original content.

= What happens if I stop my subscription? =

The translated addresses stop being served and your site returns to what it was. Your own content is never touched.

= Does it work with caching plugins? =

Yes. Each language is a distinct URL, so a page cache stores them separately with no configuration.

== Changelog ==

= 1.1.2 =
* Added: automatic updates from the Zinn Digital® control plane — the same signed, checksum-verified update path the other Zinn® plugins use. Previously a new version could not reach an installed site.

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.

= 1.0.0 =
* First release.
