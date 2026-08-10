<?php

/**
 * Customizer Builder
 * Number Field Control
 *
 * @since 6.0
 */
namespace Smashballoon\Customizer\Controls;

if (!defined('ABSPATH')) {
    exit;
}
class SB_Number_Control extends \Smashballoon\Customizer\Controls\SB_Controls_Base
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
        return 'number';
    }
    /**
     * Output Control
     *
     *
     * @since 6.0
     * @access public
     */
    public function get_control_output($controlEditingTypeModel)
    {
        ?>
		<div class="sb-control-input-ctn sbc-fb-fs" :data-contains-suffix="control.fieldSuffix !== undefined ? 'true' : 'false'">
			<button type="button" class="sb-control-input-info" :class="control.fieldPrefixAction != undefined ? 'sb-cursor-pointer' : ''" v-if="control.fieldPrefix" :disabled="control.fieldPrefixAction == undefined" :aria-hidden="control.fieldPrefixAction == undefined ? 'true' : null" :tabindex="control.fieldPrefixAction == undefined ? -1 : null" @click.prevent.default="control.fieldPrefixAction != undefined ? fieldCustomClickAction(control.fieldPrefixAction) : false">{{control.fieldPrefix.replace(/ /g,"&nbsp;")}}</button>
			<input type="number" class="sb-control-input sbc-fb-fs" :placeholder="control.placeholder ? control.placeholder : ''" :step="control.step ? control.step : 1" :max="control.max ? control.max : 1000" :min="control.min ? control.min : 0" :aria-label="control.heading || control.label || 'Number value'" v-model="<?php 
        echo $controlEditingTypeModel;
        ?>[control.id]"  @change.prevent.default="changeSettingValue(control.id,false,false, control.ajaxAction ? control.ajaxAction : false)">
			<button type="button" class="sb-control-input-info" :class="control.fieldSuffixAction != undefined ? 'sb-cursor-pointer' : ''" v-if="control.fieldSuffix" :disabled="control.fieldSuffixAction == undefined" :aria-hidden="control.fieldSuffixAction == undefined ? 'true' : null" :tabindex="control.fieldSuffixAction == undefined ? -1 : null" @click.prevent.default="control.fieldSuffixAction != undefined ? fieldCustomClickAction(control.fieldSuffixAction) : false">
				<span class="sb-control-btn-icon" aria-hidden="true" v-if="control.buttonIcon" v-html="svgIcons[control.buttonIcon]"></span>
				{{control.fieldSuffix.replace(/ /g,"&nbsp;")}}
			</button>
		</div>
		<?php 
    }
}
