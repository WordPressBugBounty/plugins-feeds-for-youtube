<?php

/**
 * Customizer Builder
 * Select Field Control
 *
 * @since 6.0
 */
namespace Smashballoon\Customizer\Controls;

if (!defined('ABSPATH')) {
    exit;
}
class SB_Select_Control extends \Smashballoon\Customizer\Controls\SB_Controls_Base
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
        return 'select';
    }
    /**
     * Output Control
     *
     * Gated selects: when a control declares `optionExtension` (truthy — set by
     * the host when one or more of the select's options is entitlement-gated,
     * e.g. a Pro-only value), the `@change` handler routes to a host-supplied
     * `changeSelectGated( control, model )` instead of the default
     * `changeSettingValue()`. `control` is the control object as passed to the
     * template; `model` is the editing-type settings model, so the host can read
     * the newly selected value at `model[ control.id ]` and revert to
     * `control.default` if the site is not entitled to it.
     *
     * The call is guarded with `typeof changeSelectGated === 'function'` so a
     * host on an older SDK that sets `optionExtension` without supplying the
     * handler degrades to the normal save path rather than throwing inside the
     * change handler. That fallback is deliberately fail-open, so the gate here
     * must not be a host's only entitlement barrier — enforce on save/render too.
     *
     * @since 6.0
     * @access public
     */
    public function get_control_output($controlEditingTypeModel)
    {
        ?>
		<div class="sb-control-input-ctn sbc-fb-fs">
			<select class="sb-control-input sbc-fb-fs" v-model="<?php 
        echo $controlEditingTypeModel;
        ?>[control.id]" :aria-label="control.heading || control.label || 'Select option'" @change.prevent.default="control.optionExtension && typeof changeSelectGated === 'function' ? changeSelectGated(control, <?php 
        echo $controlEditingTypeModel;
        ?>) : changeSettingValue(control.id,false,false, control.ajaxAction ? control.ajaxAction : false)">
				<option v-for="(opName, opValue) in control.options" :value="opValue">{{opName}}</option>
			</select>
		</div>
		<?php 
    }
}
