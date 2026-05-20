<?php
/**
 * Checkbox filter template.
 *
 * Variables: $filter (Filtron_Filter_Checkbox)
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Filtron_Filter_Checkbox $filter */
if ( ! isset( $filter ) || ! $filter instanceof Filtron_Filter_Checkbox ) {
	return;
}

$options = $filter->get_available_values();
$logic   = $filter->get_logic();
$fkey    = $filter->get_source_key();
$uid     = $filter->get_id();

if ( '' === $fkey ) {
	return;
}
?>
<div
	class="filtron-filter-checkbox"
	id="<?php echo esc_attr( $uid ); ?>"
	data-filtron-type="checkbox"
	data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
	data-filtron-logic="<?php echo esc_attr( $logic ); ?>"
>
	<?php if ( '' !== $filter->get_label() ) : ?>
		<div class="filtron-filter-checkbox__title"><?php echo $filter->get_label(); ?></div>
	<?php endif; ?>

	<ul class="filtron-filter-checkbox__list" role="list">
		<?php foreach ( $options as $opt ) : ?>
			<?php
			$val   = isset( $opt['value'] ) ? (string) $opt['value'] : '';
			$label = isset( $opt['label'] ) ? Filtron_Filter_Base::normalize_display_text( (string) $opt['label'] ) : $val;
			$cnt   = isset( $opt['count'] ) ? (int) $opt['count'] : 0;
			if ( '' === $val ) {
				continue;
			}
			$cid = $uid . '_' . sanitize_key( $val );
			?>
			<li class="filtron-filter-checkbox__item">
				<label class="filtron-filter-checkbox__label" for="<?php echo esc_attr( $cid ); ?>">
					<input
						type="checkbox"
						class="filtron-filter-checkbox__input"
						id="<?php echo esc_attr( $cid ); ?>"
						name="filtron[<?php echo esc_attr( $fkey ); ?>][]"
						value="<?php echo esc_attr( $val ); ?>"
						data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
						data-filtron-value="<?php echo esc_attr( $val ); ?>"
						<?php checked( $filter->is_value_checked( $val ), true ); ?>
					/>
					<span class="filtron-filter-checkbox__text"><?php echo esc_html( $label ); ?></span>
					<?php if ( $filter->show_counts() ) : ?>
						<span class="filtron-filter-checkbox__count">(<?php echo esc_html( (string) $cnt ); ?>)</span>
					<?php endif; ?>
				</label>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
