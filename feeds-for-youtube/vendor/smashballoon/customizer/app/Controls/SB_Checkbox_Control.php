<?php

/**
 * Customizer Builder
 * CheckBox Control
 *
 * @since 6.0
 */
namespace Smashballoon\Customizer\Controls;

if (!defined('ABSPATH')) {
    exit;
}
class SB_Checkbox_Control extends \Smashballoon\Customizer\Controls\SB_Controls_Base
{
    /**
     * Get control type.
     *
     * Getting the Control Type
     *
     * @since 6.0
     * @access public
     *
     * @return string
     */
    public function get_type()
    {
        return 'checkbox';
    }
    /**
     * Output Control
     *
     *
     * @since 6.0
     * @access public
     *
     */
    public function get_control_output($controlEditingTypeModel)
    {
        ?>
		<div class="sb-control-checkbox-ctn sbc-fb-fs" @click.prevent.default="(control.custom != undefined && control.custom == 'feedtype') ?  changeCheckboxSectionValue('type', control.value, 'feedFlyPreview') : changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled, control.ajaxAction != undefined ? control.ajaxAction : false)" :class="control.cssClass">
			<div class="sb-control-checkbox" :data-active="(control.custom != undefined && control.custom == 'feedtype') ? <?php 
        echo $controlEditingTypeModel;
        ?>['type'].includes(control.value) : <?php 
        echo $controlEditingTypeModel;
        ?>[control.id] == control.options.enabled"></div>
			<div class="sb-control-label">{{control.label}}</div>
		</div>
		<?php 
    }
}
