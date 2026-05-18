<?php
/**
 * Registers WordPress hooks (actions and filters) then runs them.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Loader
 */
class Filtron_Loader {

	/**
	 * Actions to register.
	 *
	 * @var array<int, array{hook: string, component: object|string, callback: string, priority: int, accepted_args: int}>
	 */
	protected array $actions = array();

	/**
	 * Filters to register.
	 *
	 * @var array<int, array{hook: string, component: object|string, callback: string, priority: int, accepted_args: int}>
	 */
	protected array $filters = array();

	/**
	 * Add an action to the queue.
	 *
	 * @param string              $hook          Hook name.
	 * @param object|string       $component     Object instance or class name for static methods.
	 * @param string              $callback      Method name.
	 * @param int                 $priority      Priority.
	 * @param int                 $accepted_args Accepted arguments.
	 */
	public function add_action( string $hook, $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Add a filter to the queue.
	 *
	 * @param string              $hook          Hook name.
	 * @param object|string       $component     Object instance or class name for static methods.
	 * @param string              $callback      Method name.
	 * @param int                 $priority      Priority.
	 * @param int                 $accepted_args Accepted arguments.
	 */
	public function add_filter( string $hook, $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all queued hooks with WordPress.
	 */
	public function run(): void {
		foreach ( $this->actions as $a ) {
			add_action(
				$a['hook'],
				array( $a['component'], $a['callback'] ),
				$a['priority'],
				$a['accepted_args']
			);
		}

		foreach ( $this->filters as $f ) {
			add_filter(
				$f['hook'],
				array( $f['component'], $f['callback'] ),
				$f['priority'],
				$f['accepted_args']
			);
		}
	}
}
