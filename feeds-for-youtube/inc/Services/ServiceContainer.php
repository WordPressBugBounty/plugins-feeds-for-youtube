<?php

namespace SmashBalloon\YouTubeFeed\Services;

use SmashBalloon\YouTubeFeed\Container;
use Smashballoon\Customizer\Customizer_Service;
use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\YouTubeFeed\Builder\Tooltip_Wizard;
use SmashBalloon\YouTubeFeed\Customizer\Tabs\TabsService;
use SmashBalloon\YouTubeFeed\Builder\SBY_Feed_Saver_Manager;
use SmashBalloon\YouTubeFeed\Services\Upgrade\RoutineManagerService;
use SmashBalloon\YouTubeFeed\Services\LicenseNotification;
use SmashBalloon\YouTubeFeed\Services\Integrations\Elementor\SBY_Elementor_Base;
use SmashBalloon\YouTubeFeed\Services\Integrations\Divi\SBY_Divi_Handler;
use SmashBalloon\YouTubeFeed\Services\Integrations\Analytics\SB_Analytics;
use SmashBalloon\YouTubeFeed\UsageTracking\SmashUsageTrackingService;

class ServiceContainer extends ServiceProvider
{

	protected $services = [
		CronUpdaterService::class,
		RoutineManagerService::class,
		ConfigService::class,
		AssetsService::class,
		TabsService::class,
		Customizer_Service::class,
		AdminAjaxService::class,
		DebugReportingService::class,
		ErrorReportingService::class,
		ShortcodeService::class,
		SBY_Feed_Saver_Manager::class,
		Tooltip_Wizard::class,
		LicenseNotification::class,
		SBY_Elementor_Base::class,
		SBY_Divi_Handler::class,
		SB_Analytics::class,
		SmashUsageTrackingService::class,
	];

	public function register()
	{
		$container = Container::get_instance();

		$is_pro = defined( 'SBY_PRO' ) && SBY_PRO;

		foreach ($this->services as $service) {
			// ServiceContainerPro REPLACES $services rather than merging it, and Pro
			// runs BOTH containers. Pro registers the usage tracking module through
			// its own SmashUsageTrackingProService, so skipping it here is what stops
			// the module from initialising twice (double hooks, double events).
			if ( $is_pro && SmashUsageTrackingService::class === $service ) {
				continue;
			}

			$container->get($service)->register();
		}
	}
}
