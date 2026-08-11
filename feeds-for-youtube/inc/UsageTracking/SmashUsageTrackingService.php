<?php
/**
 * ServiceContainer-compatible wrapper for SmashUsageTracking (Free).
 *
 * Instantiates SmashUsageTracking with YouTubeFreeReporter and exposes
 * a register() method for use with the ServiceContainer.
 *
 * @package SmashBalloon\YouTubeFeed\UsageTracking
 */

namespace SmashBalloon\YouTubeFeed\UsageTracking;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\YouTubeFeed\UsageTracking\YouTube\YouTubeFreeReporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SmashUsageTrackingService extends ServiceProvider {

	/**
	 * Usage tracking orchestrator.
	 *
	 * @var SmashUsageTracking
	 */
	private $tracking;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tracking = new SmashUsageTracking( new YouTubeFreeReporter() );
	}

	/**
	 * Register the module's hooks.
	 */
	public function register() {
		$this->tracking->init();
	}
}
