=== Filtron ===
Contributors: filtron
Tags: filters, woocommerce, product filters, gutenberg, ajax
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, index-backed filters for WooCommerce products, custom post types, and directory-style WordPress sites.

== Description ==

Filtron helps you build fast storefront and directory filters without repeatedly joining postmeta on every request. It stores taxonomy and meta values in a dedicated index table, then uses that index for filtering, counts, ranges, search, and price sorting.

Features:

* Admin-managed filter groups.
* Gutenberg blocks for container, checkbox, range, search, and select filters.
* Shortcode rendering for saved filter groups.
* AJAX and REST filtering responses with matching payload shape.
* Index-backed price sorting and facet counts.
* Mobile drawer UI for storefront filters.
* Grid/list result views, pagination, load more, and URL-synced filters.
* UTF-8-safe labels, including Bengali text and repaired mojibake output.

== Installation ==

1. Upload the `filtron` folder to `/wp-content/plugins/`.
2. Activate Filtron from **Plugins** in WordPress admin.
3. Open **Filtron > Tools** and run **Rebuild Index**.
4. Open **Filtron > Filter Groups**.
5. Create or edit a group and add filters.
6. Add the group to a page with either a shortcode or Gutenberg blocks.

== Shortcode Usage ==

Render a saved filter group:

`[filtron_group id="1" layout="grid"]`

Equivalent alias:

`[filtron id="1"]`

Supported attributes:

* `id` or `group_id`: saved filter group ID.
* `layout` or `view`: `grid` or `list`.
* `per_page`: result count per request.
* `orderby`: `date`, `title`, or `price`.
* `order`: `ASC` or `DESC`.

Example:

`[filtron_group id="1" layout="grid" per_page="12" orderby="price" order="ASC"]`

== Gutenberg Blocks ==

Use **Filtron (container)** as the parent block. Add filter blocks inside it:

* Filtron checkbox
* Filtron range
* Filtron search
* Filtron select

The container controls the saved group, post type scope, result layout, pagination size, and default sorting. Child filter blocks inherit the container group context in the editor and frontend.

== Index Notes ==

Filtron queries `wp_filtron_index` instead of joining postmeta repeatedly on every filter request. Rebuild the index after:

* Product or post imports.
* Bulk price updates.
* Taxonomy or meta migrations.
* Any case where counts or price sorting look stale.

Price sorting uses the indexed `_price` numeric value, so stale indexes can make sorting appear incorrect until rebuilt.

== Frequently Asked Questions ==

= Does Filtron require WooCommerce? =

WooCommerce is recommended for product filtering, but Filtron can index custom post types, taxonomies, and post meta.

= Why should I rebuild the index? =

Filtron uses its own index table for fast filtering. Rebuilding keeps filter counts, range values, and price sorting aligned with current WordPress content.

= Can I use Filtron without Gutenberg? =

Yes. Use the `[filtron_group]` or `[filtron]` shortcode to render saved filter groups.

= Does the select filter support counts and URL sync? =

Yes. Select filters support placeholder text, counts, AJAX filtering, and URL-synced values.

= Does Filtron support non-English labels? =

Yes. Filtron normalizes UTF-8 display text and repairs common mojibake output for labels such as Bengali text.

== Screenshots ==

1. Filter group admin screen with shortcode copy controls.
2. Gutenberg Filtron container with select filter.
3. Frontend product filter grid with mobile drawer.
4. Settings page for cleanup and frontend theme colors.

== Changelog ==

= 1.0.1 =

* Polished frontend filter, drawer, and product card styling toward the reference design.
* Improved storefront stylesheet cache busting through the plugin version.

= 1.0.0 =

* Initial release.
* Added admin filter groups.
* Added shortcode rendering for saved groups.
* Added Gutenberg container, checkbox, range, search, and select blocks.
* Added AJAX and REST filter endpoints.
* Added index-backed price sorting.
* Added mobile drawer UI and guest/frontend QA hardening.
