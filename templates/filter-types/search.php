<?php
/**
 * Search filter — input + dropdown (JS: filtron-frontend.js).
 *
 * Variables: $filter (Filtron_Filter_Search)
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Filtron_Filter_Search $filter */
if ( ! isset( $filter ) || ! $filter instanceof Filtron_Filter_Search ) {
	return;
}

$uid        = $filter->get_id();
$fkey       = $filter->get_source_key();
$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
$nonce      = wp_create_nonce( 'filtron_filter_nonce' );
$param_name = $filter->get_url_param_name();
$term       = $filter->get_search_term();
$listbox_id = $uid . '_listbox';
?>
<div
	class="filtron-filter-search"
	id="<?php echo esc_attr( $uid ); ?>"
	data-filtron-type="search"
	data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
	data-filtron-min-chars="<?php echo esc_attr( (string) Filtron_Filter_Search::MIN_CHARS ); ?>"
	data-filtron-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
	data-filtron-nonce="<?php echo esc_attr( $nonce ); ?>"
	data-filtron-action="filtron_search_suggest"
	data-filtron-url-param="<?php echo esc_attr( $param_name ); ?>"
>
	<?php if ( '' !== $filter->get_label() ) : ?>
		<label class="filtron-filter-search__label" for="<?php echo esc_attr( $uid . '_input' ); ?>">
			<?php echo $filter->get_label(); ?>
		</label>
	<?php endif; ?>

	<div class="filtron-filter-search__field">
		<input
			type="search"
			id="<?php echo esc_attr( $uid . '_input' ); ?>"
			class="filtron-filter-search__input"
			name="<?php echo esc_attr( $param_name ); ?>"
			value="<?php echo esc_attr( $term ); ?>"
			data-filtron-type="search"
			data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
			autocomplete="off"
			role="combobox"
			aria-autocomplete="list"
			aria-expanded="false"
			aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
		/>
		<div class="filtron-filter-search__dropdown" hidden>
			<ul
				id="<?php echo esc_attr( $listbox_id ); ?>"
				class="filtron-filter-search__list"
				role="listbox"
			></ul>
			<div class="filtron-filter-search__empty" role="status" hidden>
				<?php echo esc_html__( 'No results found', 'filtron' ); ?>
			</div>
		</div>
	</div>
</div>
