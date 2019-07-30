<?php

require_once __DIR__ . '/class-jetpack-connection-controls.php';

/**
 * The Pilot is in control of setting up the cron job for monitoring JP connections and sending out alerts if anything is wrong.
 * Will only run if the `WPCOM_VIP_RUN_CONNECTION_PILOT` constant is defined and set to true.
 */
class WPCOM_VIP_Jetpack_Connection_Pilot {
	/**
	 * The option name used for keeping track of successful connection checks.
	 */
	const HEALTHCHECK_OPTION_NAME = 'vip_jetpack_connection_pilot_healthcheck';

	/**
	 * Cron action that runs the connection pilot checks.
	 */
	const CRON_ACTION = 'wpcom_vip_run_jetpack_connection_pilot';

	/**
	 * The schedule the cron job runs on. Update in 000-vip-init.php as well.
	 *
	 * Schedule changes can take up to 24 hours to take effect.
	 * See the a8c_cron_control_clean_legacy_data event for more details.
	 */
	const CRON_SCHEDULE = 'hourly';
	
	/**
	 * The healtcheck option's current data.
	 *
	 * Example: [ 'site_url' => 'https://example.go-vip.co', 'cache_site_id' => 1234, 'last_healthcheck' => 1555124370 ]
	 *
	 * @var mixed False if doesn't exist, else an array with the data shown above.
	 */
	private $healthcheck_option;

	/**
	 * Singleton
	 * 
	 * @var WPCOM_VIP_Jetpack_Connection_Pilot Singleton instance
	 */
	private static $instance = null;

	private function __construct() {
		$this->init_actions();
		
		$this->healthcheck_option = get_option( self::HEALTHCHECK_OPTION_NAME );
	}

	/**
	 * Initiate an instance of this class if one doesn't exist already.
	 */
	public static function init() {
		if ( ! self::should_run_connection_pilot() ) {
			return;
		}

		if ( ! ( self::$instance instanceof WPCOM_VIP_Jetpack_Connection_Pilot ) ) {
			self::$instance = new WPCOM_VIP_Jetpack_Connection_Pilot();
		}

		return self::$instance;
	}

	/**
	 * Hook any relevant actions
	 */
	public function init_actions() {
		// Ensure the internal cron job has been added. Should already exist as an internal Cron Control job.
		add_action( 'init', array( $this, 'schedule_cron' ) );
		add_action( self::CRON_ACTION, array( $this, 'run_connection_pilot' ) );

		add_filter( 'vip_jetpack_connection_pilot_should_reconnect', array( $this, 'filter_vip_jetpack_connection_pilot_should_reconnect' ), 10, 2 );
	}

	public function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
			wp_schedule_event( strtotime( sprintf( '+%d minutes', mt_rand( 1, 60 ) ) ), self::CRON_SCHEDULE, self::CRON_ACTION );
		}
	}

	/**
	 * The main cron job callback.
	 * Checks the JP connection and alerts/auto-resolves when there are problems.
	 *
	 * Needs to be static due to how it is added to cron control.
	 */
	public function run_connection_pilot() {
		if ( ! self::should_run_connection_pilot() ) {
			return;
		}

		$is_connected = WPCOM_VIP_Jetpack_Connection_Controls::jetpack_is_connected();

		if ( true === $is_connected ) {
			// Everything checks out. Update the healthcheck option and move on.
			$this->update_healthcheck();

			return;
		}

		// Not connected, maybe reconnect
		if ( ! self::should_attempt_reconnection( $is_connected ) ) {
			$this->send_alert( 'Jetpack is disconnected. No reconnection attempt was made.' );

			return;
		}

		// Got here, so we _should_ attempt a reconnection for this site
		$this->reconnect();
	}

	/**
	 * Perform a JP reconnection
	 */
	public function reconnect() {
		// Attempt a reconnect
		$connection_attempt = WPCOM_VIP_Jetpack_Connection_Controls::connect_site( 'skip_connection_tests' );

		if ( true === $connection_attempt ) {
			if ( ! empty( $this->healthcheck_option['cache_site_id'] ) && (int) Jetpack_Options::get_option( 'id' ) !== (int) $this->healthcheck_option['cache_site_id'] ) {
				$this->send_alert( 'Alert: Jetpack was automatically reconnected, but the connection may have changed cache sites. Needs manual inspection.' );

				return;
			}

			$this->send_alert( 'Jetpack was successfully (re)connected!' );

			return;
		}

		// Reconnection failed
		$this->send_alert( 'Jetpack (re)connection attempt failed.', $connection_attempt );
	}

	// TODO heartbeat?
	public function update_healthcheck() {
		return update_option( self::HEALTHCHECK_OPTION_NAME, array(
			'site_url'         => get_site_url(),
			'cache_site_id'    => (int) Jetpack_Options::get_option( 'id' ),
			'last_healthcheck' => time(),
		), false );
	}

	public function filter_vip_jetpack_connection_pilot_should_reconnect( $should, $error = null ) {
		$error_code = null;

		if ( $error && is_wp_error( $error ) ) {
			$error_code = $error->get_error_code();
		}

		// 1) Had an error
		switch( $error_code ) {
			case 'jp-cxn-pilot-missing-constants':
			case 'jp-cxn-pilot-development-mode':
				$this->send_alert( 'Jetpack cannot currently be connected on this site due to the environment. JP may be in development mode.', $error );

				return false;

			// It is connected but not under the right account.
			case 'jp-cxn-pilot-not-vip-owned':
				$this->send_alert( 'Jetpack is connected to a non-VIP account.', $error );

				return false;
		}

		// 2) Check the last healthcheck to see if the URLs match.
		if ( ! empty( $this->healthcheck_option['site_url'] ) ) {
			if ( $this->healthcheck_option['site_url'] === get_site_url() ) {
				// Not connected, but current url matches previous url, attempt a reconnect
	
				return true;
			}

			// Not connected and current url doesn't match previous url, don't attempt reconnection
			$this->notify_pilot( 'Jetpack is disconnected, and it appears the domain has changed.' );

			return false;
		}

		return $should;
	}

	/**
	 * Send an alert to IRC and Slack.
	 *
	 * Example message:
	 * Jetpack is disconnected, but was previously connected under the same domain.
	 * Site: example.go-vip.co (ID 123). The last known connection was on August 25, 12:11:14 UTC to Cache ID 65432 (example.go-vip.co).
	 * Jetpack connection error: [jp-cxn-pilot-not-active] Jetpack is not currently active.
	 *
	 * @param string   $message optional.
	 * @param WP_Error $wp_error optional.
	 * @param array    $last_healthcheck optional.
	 *
	 * @return mixed True if the message was sent to IRC, false if it failed. If sandboxed, will just return the message string.
	 */
	protected function send_alert( $message = '', $wp_error = null, $last_healthcheck = null ) {
		$message .= sprintf( ' Site: %s (ID %d).', get_site_url(), defined( 'VIP_GO_APP_ID' ) ? VIP_GO_APP_ID : 0 );

		if ( isset( $last_healthcheck['site_url'], $last_healthcheck['cache_site_id'], $last_healthcheck['last_healthcheck'] ) ) {
			$message .= sprintf(
				' The last known connection was on %s UTC to Cache Site ID %d (%s).',
				date( 'F j, H:i', $last_healthcheck['last_healthcheck'] ), $last_healthcheck['cache_site_id'], $last_healthcheck['site_url']
			);
		}

		if ( is_wp_error( $wp_error ) ) {
			$message .= sprintf( ' Jetpack connection error: [%s] %s', $wp_error->get_error_code(), $wp_error->get_error_message() );
		}

		if ( ( defined( 'WPCOM_SANDBOXED' ) && WPCOM_SANDBOXED ) || ( ! defined( 'ALERT_SERVICE_ADDRESS' ) ) ) {
			error_log( $message );

			return $message; // Just return the message, as posting to IRC won't work.
		}

		return wpcom_vip_irc( '#vip-jp-cxn-monitoring', $message );
	}

	/**
	 * Checks if the connection pilot should run.
	 *
	 * @return bool True if the connection pilot should run.
	 */
	public static function should_run_connection_pilot() {
		$should = defined( 'VIP_JETPACK_CONNECTION_PILOT_SHOULD_RUN' ) ? VIP_JETPACK_CONNECTION_PILOT_SHOULD_RUN : false;
		
		return apply_filters( 'vip_jetpack_connection_pilot_should_run', $should );
	}

	/**
	 * Checks if a reconnection should be attempted
	 * 
	 * @param $error WP_Error Optional error thrown by the connection check
	 * @return bool True if a reconnect should be attempted
	 */
	public static function should_attempt_reconnection( $error = null ) {
		$should = defined( 'VIP_JETPACK_CONNECTION_PILOT_SHOULD_RECONNECT' ) ? VIP_JETPACK_CONNECTION_PILOT_SHOULD_RECONNECT : false;
		
		return apply_filters( 'vip_jetpack_connection_pilot_should_reconnect', $should, $error );
	}
}

WPCOM_VIP_Jetpack_Connection_Pilot::init();
