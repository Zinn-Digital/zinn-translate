=== Zinn® Translate ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: translation, multilingual, seo, hreflang, localization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
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

= 1.0.0 =
* First release.
