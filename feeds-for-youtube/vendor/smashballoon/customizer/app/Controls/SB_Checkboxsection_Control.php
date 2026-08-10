<?php

/**
 * Customizer Builder
 * CheckBox Section Control
 *
 * @since 6.0
 */
namespace Smashballoon\Customizer\Controls;

if (!defined('ABSPATH')) {
    exit;
}
class SB_Checkboxsection_Control extends \Smashballoon\Customizer\Controls\SB_Controls_Base
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
        return 'checkboxsection';
    }
    /**
     * Output Control
     *
     *
     * @since 6.0
     * @access public
     *
     * @return HTML
     */
    public function get_control_output($controlEditingTypeModel)
    {
        ?>
		<div class="sb-control-checkboxsection-header" v-if="control.header">
			<div class="sb-control-checkboxsection-name">
				<span aria-hidden="true" v-html="svgIcons['preview']"></span>
				<strong class="">{{genericText.name}}</strong>
			</div>
			<strong>{{genericText.edit}}</strong>
		</div>
		<div class="sb-control-checkbox-ctn sbc-fb-fs" role="group" :aria-label="control.label" :data-disabled="control.checkExtensionPopup != undefined ? false : control.disabled" :data-default-cursor="!control.section">
			<span class="sb-control-checkbox-hover sb-tr-2" aria-hidden="true"></span>
			<button type="button" class="sb-control-checkbox" role="checkbox" :aria-checked="checkboxSectionValueExists(control.id, control.value)" :aria-label="control.label" @click.stop.prevent.default="control.disabled ? activateAPIForm() : changeCheckboxSectionValue(control.id, control.value)" @keydown.enter.stop.prevent="control.disabled ? activateAPIForm() : changeCheckboxSectionValue(control.id, control.value)" @keydown.space.stop.prevent="control.disabled ? activateAPIForm() : changeCheckboxSectionValue(control.id, control.value)" :data-active="checkboxSectionValueExists(control.id, control.value)"></button>
			<button type="button" class="sbc-fb-fs sb-control-checkboxsection-open" :aria-controls="control.section && control.section.id ? control.section.id : null" :aria-label="control.label" :disabled="!control.section || control.disabled" @click.prevent.default="control.disabled ? null : control.section ? switchNestedSection(control.section.id, control.section) : null" @keydown.enter.prevent="control.disabled ? null : control.section ? switchNestedSection(control.section.id, control.section) : null" @keydown.space.prevent="control.disabled ? null : control.section ? switchNestedSection(control.section.id, control.section) : null" :data-active="checkboxSectionValueExists(control.id, control.value)">
				<strong class="sb-control-label">
					<span aria-hidden="true" v-html="svgIcons[control.icon]" v-if="control.icon"></span>
					{{control.label}}
				</strong>
				<svg v-if="control.section" aria-hidden="true" class="sb-control-checkboxsection-btn" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><!--! Font Awesome Pro 6.1.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path d="M96 480c-8.188 0-16.38-3.125-22.62-9.375c-12.5-12.5-12.5-32.75 0-45.25L242.8 256L73.38 86.63c-12.5-12.5-12.5-32.75 0-45.25s32.75-12.5 45.25 0l192 192c12.5 12.5 12.5 32.75 0 45.25l-192 192C112.4 476.9 104.2 480 96 480z"/></svg>
			</button>
		</div>
		<?php 
    }
}
