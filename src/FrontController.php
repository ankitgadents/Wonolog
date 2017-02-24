<?php # -*- coding: utf-8 -*-
/*
 * This file is part of the Inpsyde wonolog package.
 *
 * (c) Inpsyde GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\Wonolog;

use Inpsyde\Wonolog\Data\LogDataInterface;
use Inpsyde\Wonolog\HookListener\ActionListenerInterface;
use Inpsyde\Wonolog\HookListener\FilterListenerInterface;
use Inpsyde\Wonolog\HookListener\HookListenerInterface;
use Inpsyde\Wonolog\HookListener\HookPriorityInterface;
use Inpsyde\Wonolog\Processor\WpContextProcessor;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * "Entry point" for package bootstrapping.
 *
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package wonolog
 * @license http://opensource.org/licenses/MIT MIT
 */
class FrontController {

	const ACTION_LOADED = 'wonolog.loaded';
	const ACTION_SETUP = 'wonolog.setup';
	const FILTER_ENABLE = 'wonolog.enable';

	/**
	 * @var HandlerInterface
	 */
	private $default_handler;

	/**
	 * Bootstrap the package once per request.
	 */
	public static function boot() {

		$instance = new static();
		$instance->setup();
	}

	/**
	 * FrontController constructor.
	 *
	 * @param HandlerInterface $default_handler
	 */
	public function __construct( HandlerInterface $default_handler = NULL ) {

		$this->default_handler = $default_handler;
	}

	/**
	 * Initialize the package object.
	 *
	 * @param int $priority
	 */
	public function setup( $priority = 100 ) {

		if ( did_action( self::ACTION_SETUP ) || ! apply_filters( self::FILTER_ENABLE, TRUE ) ) {
			return;
		}

		do_action( self::ACTION_SETUP );

		$this->setup_php_error_handler();

		$default_handler    = $this->setup_default_handler();
		$default_processors = apply_filters( 'wonolog.default-processors', [ new WpContextProcessor() ] );

		$listener = [ new LogActionSubscriber( new Channels(), $default_handler, $default_processors ), 'listen' ];

		add_action( LOG, $listener, $priority, PHP_INT_MAX );

		foreach ( Logger::getLevels() as $level => $level_code ) {
			// $level_code is from 100 (DEBUG) to 600 (EMERGENCY) this makes hook priority based on level priority
			add_action( LOG . strtolower( $level ), $listener, $priority + ( 601 - $level ), PHP_INT_MAX );
		}

		$this->setup_hook_listeners();

		do_action( self::ACTION_LOADED );
	}

	/**
	 * Initialize PHP error handler.
	 */
	private function setup_php_error_handler() {

		if ( ! apply_filters( 'wonolog.enable-php-error-handler', TRUE ) ) {
			return;
		}

		$handler = new PhpErrorController();
		$handler->init();

		// Ensure that CHANNEL_PHP_ERROR error is there
		add_filter(
			Channels::FILTER_CHANNELS,
			function ( array $channels ) {

				$channels[] = PhpErrorController::CHANNEL;

				return $channels;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Setup default handler.
	 *
	 * @return HandlerInterface|NULL
	 */
	private function setup_default_handler() {

		$default_handler = apply_filters( 'wonolog.default-handler', NULL );
		if ( $default_handler instanceof HandlerInterface || $default_handler === FALSE ) {
			$default_handler and $this->default_handler = $default_handler;

			return NULL;
		}

		$folder = getenv( 'WONOLOG_HANDLER_FILE_DIR' ) ? : trailingslashit( WP_CONTENT_DIR ) . 'wonolog';
		$folder = apply_filters( 'wonolog.default-handler-folder', $folder );

		$default_format = 'Y/m-d';
		$name_format    = apply_filters( 'wonolog.default-handler-name-format', $default_format );
		( is_string( $name_format ) && $name_format ) or $name_format = $default_format;
		$filename = date( $name_format );
		pathinfo( $filename, PATHINFO_EXTENSION ) or $filename .= '.log';

		$fullpath = "{$folder}/{$filename}";
		$fullpath = apply_filters( 'wonolog.default-handler-filepath', $fullpath );

		$dir = dirname( $fullpath );

		if ( ! wp_mkdir_p( $dir ) ) {
			return NULL;
		}

		$log_level = LogLevel::instance();

		return new StreamHandler( $fullpath, $log_level->default_level() );
	}

	/**
	 * Setup registered hook listeners using the hook registry.
	 */
	private function setup_hook_listeners() {

		$hook_listeners_registry = new HookListenersRegistry();
		do_action( HookListenersRegistry::ACTION_REGISTER, $hook_listeners_registry );

		$hook_listeners = $hook_listeners_registry->listeners();

		array_walk( $hook_listeners, [ $this, 'setup_hook_listener' ] );

		$hook_listeners_registry->flush();
	}

	/**
	 * @param HookListenerInterface $listener
	 */
	private function setup_hook_listener( HookListenerInterface $listener ) {

		$hooks = (array) $listener->listen_to();

		array_walk( $hooks, [ $this, 'listen_hook' ], $listener );
	}

	/**
	 * @param string                $hook
	 * @param int                   $i
	 * @param HookListenerInterface $listener
	 *
	 * @return bool
	 */
	private function listen_hook( $hook, $i, HookListenerInterface $listener ) {

		$is_filter = $listener instanceof FilterListenerInterface;
		if ( ! $is_filter && ! $listener instanceof ActionListenerInterface ) {
			return false;
		}

		/**
		 * @return null
		 *
		 * @var FilterListenerInterface|ActionListenerInterface|HookPriorityInterface $listener
		 * @var bool                                                                  $is_filter
		 */
		$callback = function () use ( $listener, $is_filter ) {

			$args = func_get_args();

			if ( ! $is_filter ) {
				$log = $listener->update( $args );
				$log instanceof LogDataInterface and do_action( LOG, $log );
			}

			return $is_filter ? $listener->filter( $args ) : NULL;
		};

		$priority = $listener instanceof HookPriorityInterface ? (int) $listener->priority() : PHP_INT_MAX - 10;

		$filtered_priority = apply_filters( HookPriorityInterface::FILTER_PRIORITY, $priority, $listener );
		is_int( $filtered_priority ) and $priority = $filtered_priority;

		return $is_filter
			? add_filter( $hook, $callback, $priority, PHP_INT_MAX )
			: add_action( $hook, $callback, $priority, PHP_INT_MAX );
	}

}