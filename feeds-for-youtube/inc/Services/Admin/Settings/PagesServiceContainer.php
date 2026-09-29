<?php

namespace SmashBalloon\YouTubeFeed\Services\Admin\Settings;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\YouTubeFeed\Container;

class PagesServiceContainer extends ServiceProvider {
	private $services = [
		SettingsPage::class,
		SingleVideoPage::class,
		HelpPage::class,
		// AboutPage's menu is replaced by the shared AboutUs package (see
		// AboutPage::$has_menu = false), but it is still registered because it
		// is the sole provider of the `sby_localized_settings` -> pluginInfo
		// data and the sby_install_addon / sby_activate_addon ajax handlers that
		// the onboarding wizard's cross-sell step depends on.
		AboutPage::class,
		SetupPage::class,
	];

	public function register() {
		$container = Container::get_instance();
		foreach ( $this->services as $service ) {
			$container->get($service)->register();
		}
	}
}