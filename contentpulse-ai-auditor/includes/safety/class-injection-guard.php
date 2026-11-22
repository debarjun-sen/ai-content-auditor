<?php
/**
 * Prompt-injection defenses for site content sent to the model.
 *
 * Two layers: (1) every piece of site content is wrapped in an
 * <untrusted_content> envelope the system prompt tells Claude to treat as data,
 * with any attempt to close the envelope neutralized; (2) a heuristic detector
 * flags instruction-like text aimed at AI systems so a human can review it.
 *
 * @package AICA
 */

namespace AICA\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * Injection guard.
 */
class Injection_Guard {

	/**
	 * Patterns (case-insensitive) that indicate text addressed to an AI agent.
	 *
	 * @var string[]
	 */
	const PATTERNS = array(
		'ignore_instructions' => '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|any|system)\b[^.\n]{0,30}\b(instructions?|prompts?|rules?|directions?)\b/i',
		'role_override'       => '/\byou are now (an? |the )?(unrestricted|jailbroken|uncensored|different|new) (ai|assistant|model|agent|bot)\b|\bact as (an? |the )?(unrestricted|jailbroken|uncensored) \w+|\bnew (system )?instructions?\s*:/i',
		'system_prompt'       => '/\b(system|developer) prompt\b|<\/?\s*(system|assistant|untrusted_content)\b|\[\/?INST\]|<\|im_(start|end)\|>/i',
		'audit_tampering'     => '/\b(ai|llm|bot|agent|auditor|assistant|claude|gpt)s?\b[^.\n]{0,60}\b(mark|report|rate|score|treat|consider)\b[^.\n]{0,40}\b(pass(ing|ed)?|perfect|clean|compliant|no (issues|problems|errors)|100)\b/i',
		'tool_coercion'       => '/\b(call|invoke|run|use)\b[^.\n]{0,20}\b(tool|function)\b[^.\n]{0,30}\b(record_finding|apply_fix|delete|update)\b/i',
		'exfiltration'        => '/\b(reveal|print|output|send|leak)\b[^.\n]{0,40}\b(api key|secret|password|credentials?|system prompt)\b/i',
	);

	/**
	 * Scan text (raw HTML incl. comments and hidden markup) for injection attempts.
	 *
	 * @param string $text Raw content.
	 * @return array<int,array{pattern:string,match:string}>
	 */
	public static function detect( $text ) {
		$text = (string) $text;
		// Look inside HTML comments and attributes too: strip tags but keep comment bodies.
		$haystack = preg_replace( '/<!--(.*?)-->/s', ' $1 ', $text );
		// Plain strip_tags() on purpose: wp_strip_all_tags() deletes <script>/<style> bodies, where injected text can hide.
		$haystack = html_entity_decode( strip_tags( (string) $haystack ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$haystack = preg_replace( '/\s+/u', ' ', $haystack );
		// Also scan the raw markup: fake <system>/<untrusted_content> tags vanish after strip_tags().
		$raw = preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$hits = array();
		foreach ( self::PATTERNS as $name => $regex ) {
			if ( preg_match( $regex, (string) $haystack, $m ) || preg_match( $regex, (string) $raw, $m ) ) {
				$hits[] = array(
					'pattern' => $name,
					'match'   => self::short( $m[0] ),
				);
			}
		}
		return $hits;
	}

	/**
	 * Wrap untrusted content for inclusion in a tool result.
	 *
	 * @param string $content Untrusted text.
	 * @param string $source  Source label, e.g. "post:42".
	 * @return string
	 */
	public static function wrap( $content, $source ) {
		$content = (string) $content;
		// Neutralize attempts to break out of the envelope.
		$content = preg_replace( '/<\s*\/?\s*untrusted_content[^>]*>/i', '[removed-tag]', $content );
		$source  = preg_replace( '/[^a-z0-9:_-]/i', '', (string) $source );
		return '<untrusted_content source="' . $source . '">' . $content . '</untrusted_content>';
	}

	/**
	 * Truncate a match for display.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function short( $s ) {
		$s = trim( (string) $s );
		return strlen( $s ) > 120 ? substr( $s, 0, 117 ) . '...' : $s;
	}
}
