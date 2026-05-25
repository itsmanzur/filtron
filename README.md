# Filtron

Fast, index-backed filters for WooCommerce products, custom post types, and directory-style WordPress sites.

Filtron provides:

- Admin-managed filter groups.
- Gutenberg blocks for container, checkbox, range, search, and select filters.
- Shortcode rendering for saved filter groups.
- AJAX and REST filtering responses with matching payload shape.
- Index-backed price sorting and facet counts.
- Mobile drawer UI, grid/list results, pagination, load more, and URL-synced filters.

## Requirements

- WordPress 6.0 or newer.
- PHP 8.0 or newer.
- WooCommerce is recommended for product filtering, but custom post types and post meta are supported through the Filtron index.

## Setup

1. Activate the Filtron plugin.
2. Open **Filtron > Tools** and run **Rebuild Index** after activation, imports, migrations, or bulk product updates.
3. Open **Filtron > Filter Groups**.
4. Create or edit a group and add filters.
5. Add the group to a page using either a shortcode or Gutenberg blocks.

## Shortcode

Render a saved filter group:

```text
[filtron_group id="1" layout="grid"]
```

Equivalent alias:

```text
[filtron id="1"]
```

Supported attributes:

- `id` or `group_id`: saved filter group ID.
- `layout` or `view`: `grid` or `list`.
- `per_page`: result count per request.
- `orderby`: `date`, `title`, or `price`.
- `order`: `ASC` or `DESC`.

Example:

```text
[filtron_group id="1" layout="grid" per_page="12" orderby="price" order="ASC"]
```

## Gutenberg

Use **Filtron (container)** as the parent block. Add filter blocks inside it:

- **Filtron checkbox**
- **Filtron range**
- **Filtron search**
- **Filtron select**

The container controls the saved group, post type scope, result layout, pagination size, and default sorting. Child filter blocks inherit the container group context in the editor and frontend.

## Filter Types

### Checkbox

Use for taxonomy terms or indexed meta values where visitors can select one or more values.

### Select

Use for compact taxonomy or meta filters. It supports a placeholder, counts, URL sync, AJAX filtering, and Gutenberg block configuration.

### Range

Use for numeric meta values such as `_price`. Range filtering and price sorting rely on `filter_value_num` in the Filtron index.

### Search

Use for indexed taxonomy or meta value search. Suggestions are loaded through Filtron AJAX endpoints.

## Index Notes

Filtron queries `wp_filtron_index` instead of joining postmeta repeatedly on every filter request. Rebuild the index when:

- Products or posts were imported.
- Product prices changed in bulk.
- Taxonomies/meta were migrated.
- Counts or price sorting look stale.

Price sorting uses the indexed `_price` numeric value, so stale indexes can make sorting appear incorrect until rebuilt.

## Frontend QA Checklist

Before release, verify:

- Shortcode page renders filters and products.
- Gutenberg page renders filters and products.
- Select filter updates results and active count.
- Checkbox/range/search filters update results.
- Price ascending and descending sorting are correct.
- AJAX and REST counts match for the same filter payload.
- Mobile drawer opens and closes.
- No horizontal overflow on mobile.
- Bengali or other UTF-8 labels render without mojibake.
- Empty and inactive-only groups show the expected admin/guest state.

## Development

Useful checks:

```bash
php -l filtron.php
php -l includes/filters/class-filtron-filter-base.php
php -l includes/integrations/class-filtron-shortcode.php
```

This repository intentionally avoids committing temporary browser or QA harness files.
