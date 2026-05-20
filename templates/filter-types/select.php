<?php
/**
 * Select filter template.
 *
 * Variables: $filter (Filtron_Filter_Select)
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Filtron_Filter_Select $filter */
if ( ! isset( $filter ) || ! $filter instanceof Filtron_Filter_Select ) {
	return;
}

$options  = $filter->get_available_values();
$fkey     = $filter->get_source_key();
$uid      = $filter->get_id();
$selected = $filter->get_selected_value();

if ( '' === $fkey ) {
	return;
}
?>
<div
	class="filtron-filter-select"
	id="<?php echo esc_attr( $uid ); ?>"
	data-filtron-type="select"
	data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
>
	<?php if ( '' !== $filter->get_label() ) : ?>
		<label class="filtron-filter-select__label" for="<?php echo esc_attr( $uid . '_select' ); ?>">
			<?php echo $filter->get_label(); ?>
		</label>
	<?php endif; ?>

	<select
		id="<?php echo esc_attr( $uid . '_select' ); ?>"
		class="filtron-filter-select__input"
		name="filtron_<?php echo esc_attr( $fkey ); ?>"
		data-filtron-type="select"
		data-filtron-key="<?php echo esc_attr( $fkey ); ?>"
	>
		<option value=""><?php echo esc_html( $filter->get_placeholder() ); ?></option>
		<?php foreach ( $options as $opt ) : ?>
			<?php
			$val   = isset( $opt['value'] ) ? (string) $opt['value'] : '';
			$label = isset( $opt['label'] ) ? Filtron_Filter_Base::normalize_display_text( (string) $opt['label'] ) : $val;
			$cnt   = isset( $opt['count'] ) ? (int) $opt['count'] : 0;
			if ( '' === $val ) {
				continue;
			}
			$option_label = $filter->show_counts()
				? sprintf(
					/* translators: 1: filter option label, 2: result count */
					__( '%1$s (%2$d)', 'filtron' ),
					$label,
					$cnt
				)
				: $label;
			?>
			<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $selected, $val ); ?>>
				<?php echo esc_html( $option_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</div>
