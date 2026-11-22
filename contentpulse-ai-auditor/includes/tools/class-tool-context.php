<?php
/**
 * Per-invocation context passed to tools.
 *
 * @package AICA
 */

namespace AICA\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Mutable context shared across the tool calls of one scan step.
 */
class Tool_Context {

	/**
	 * Scan the call belongs to (0 = none).
	 *
	 * @var int
	 */
	public $scan_id = 0;

	/**
	 * Caller surface: agent|mcp|ability|cli.
	 *
	 * @var string
	 */
	public $source = 'agent';

	/**
	 * Max distinct posts that may be read in this scan.
	 *
	 * @var int
	 */
	public $max_posts = 25;

	/**
	 * Distinct post IDs read so far.
	 *
	 * @var int[]
	 */
	public $posts_seen = array();

	/**
	 * Constructor.
	 *
	 * @param int    $scan_id    Scan.
	 * @param string $source     Surface.
	 * @param int    $max_posts  Post budget.
	 * @param int[]  $posts_seen Already-read posts.
	 */
	public function __construct( $scan_id = 0, $source = 'agent', $max_posts = 25, array $posts_seen = array() ) {
		$this->scan_id    = (int) $scan_id;
		$this->source     = $source;
		$this->max_posts  = (int) $max_posts;
		$this->posts_seen = array_map( 'intval', $posts_seen );
	}

	/**
	 * Register a post read; false if it would exceed the budget.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public function touch_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( in_array( $post_id, $this->posts_seen, true ) ) {
			return true;
		}
		if ( count( $this->posts_seen ) >= $this->max_posts ) {
			return false;
		}
		$this->posts_seen[] = $post_id;
		return true;
	}
}
