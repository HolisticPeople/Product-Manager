<?php
/** Single native structured-value ACF editor; no repeater-row side storage. */
if (!defined('ABSPATH') || !class_exists('acf_field')) { exit; }
final class HP_PM_ACF_Natural_Constituents extends acf_field
{
    public function initialize()
    {
        $this->name = 'hp_natural_constituents';
        $this->label = 'Natural constituents (structured)';
        $this->category = 'content';
    }
    public function render_field($field)
    {
        $value = $field['value'] ?? [];
        if ($value === '') { $value = []; }
        $json = is_array($value) ? wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
        echo '<textarea rows="8" class="widefat" name="' . esc_attr($field['name']) . '" aria-label="Natural constituents">' . esc_textarea((string) $json) . '</textarea>';
        echo '<p class="description">Reviewed source rows: sourceName, canonicalIngredient (null or reviewed native reference), chemicalClass, amount, species, isomer. Use UNKNOWN for missing facts. This field does not assign ingredient memberships.</p>';
    }
}
