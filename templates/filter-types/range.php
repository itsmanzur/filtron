<?php
/**
 * Range slider wrapper (noUiSlider attaches to __track in filtron-frontend.js).
 *
 * Variables: $filter (Filtron_Filter_Range)
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Filtron_Filter_Range $filter */
if ( ! isset( $filter ) || ! $filter instanceof Filtron_Filter_Range ) {
	return;
}

$mm       = $filter->get_min_max();
$sel      = $filter->get_selected_range();
$step     = $filter->get_step();
$uid      = $filter->get_id();
$fkey     = $filter->get_source_key();
$url_slug = $filter->get_url_slug();

$min = (float) $mm['min'];
$max = (float) $mm['max'];
$cmin = isset( $sel['min'] ) ? (float) $sel['min'] : $min;
$cmax = isset( $sel['max'] ) ? (float) $sel['max'] : $max;

$cmin = min( max( $cmin, $min ), $max );
$cmax = min( max( $cmax, $min ), $max );
if ( $cmin > $cmax ) {
	$t    = $cmin;
	$cmin = $cmax;
	$cmax = $t;
}

$prefix = $filter->get_prefix();
$suffix = $filter->get_suffix();
?>
<div
	class="filtron-filter-range"
	id="<?php echo esc_attr( $uid ); ?>"
	data-filtron-type="range"
	data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
	data-filtron-url-slug="<?php echo esc_attr( $url_slug ); ?>"
	data-filtron-min="<?php echo esc_attr( (string) $min ); ?>"
	data-filtron-max="<?php echo esc_attr( (string) $max ); ?>"
	data-filtron-step="<?php echo esc_attr( (string) $step ); ?>"
	data-filtron-current-min="<?php echo esc_attr( (string) $cmin ); ?>"
	data-filtron-current-max="<?php echo esc_attr( (string) $cmax ); ?>"
>
	<?php if ( '' !== $filter->get_label() ) : ?>
		<div class="filtron-filter-range__title">
			<?php echo $filter->get_label(); ?>
		</div>
	<?php endif; ?>

	<div class="filtron-filter-range__meta" aria-hidden="true">
		<?php if ( '' !== $prefix ) : ?>
			<span class="filtron-filter-range__prefix"><?php echo esc_html( $prefix ); ?></span>
		<?php endif; ?>
		<span class="filtron-filter-range__readout">
			<span class="filtron-filter-range__readout-min"><?php echo esc_html( (string) $cmin ); ?></span>
			<span class="filtron-filter-range__sep"> — </span>
			<span class="filtron-filter-range__readout-max"><?php echo esc_html( (string) $cmax ); ?></span>
		</span>
		<?php if ( '' !== $suffix ) : ?>
			<span class="filtron-filter-range__suffix"><?php echo esc_html( $suffix ); ?></span>
		<?php endif; ?>
	</div>

	<div
		class="filtron-filter-range__track"
		id="<?php echo esc_attr( $uid . '_track' ); ?>"
		role="presentation"
	></div>
</div>
