<?php
/**
 * Runs a PHP snippet inside WordPress.
 *
 * The snippet runs in this request (not a separate process) inside a
 * static closure, with output buffering, an error handler and a shutdown
 * handler that logs fatal errors. See the description for what the AI is
 * told.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Php;

use Throwable;
use WP_Error;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RunPhp extends AbilityBase {

	const DEFAULT_TIMEOUT = 30;
	const MAX_TIMEOUT     = 300;

	/**
	 * Most bytes kept from output and from the encoded return value.
	 */
	const MAX_OUTPUT = 102400;

	/**
	 * Most warnings and notices reported.
	 */
	const MAX_ERRORS = 50;

	const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * The snippet currently running, for the shutdown handler: input and
	 * start time. Null when nothing is running.
	 *
	 * @var array|null
	 */
	private static $running = null;

	private static $shutdown_registered = false;

	public function slug(): string {
		return 'run-php';
	}

	public function label(): string {
		return __( 'Run PHP code', 'wproadie' );
	}

	public function description(): string {
		return __( 'Executes a PHP snippet inside this WordPress site and returns its return value, anything it printed, any warnings or notices, and any uncaught exception. DANGER: the code runs with full access as the connected administrator: it can read and change anything WordPress, the database or the file system allows, including secrets. Changes CANNOT be undone with WPRoadie undo. This tool is off by default and only available in staging mode; use it only when no other WPRoadie tool can do the job, and prefer read-only code (inspecting state) over changes. Write plain PHP without the opening <?php tag, e.g. `return get_option( "blogname" );`. WordPress is fully loaded; use `global $wpdb;` to reach globals. Variables do not persist between calls. Use `return` to send back a value (arrays and objects are converted to JSON where possible). Do not call exit or die (refused); do not declare functions or classes that may already exist, since redeclaring is a fatal error that ends the request without a result. Output is cut at 100 KB. The timeout is a CPU-time hint enforced by PHP (sleeping and waiting do not count).', 'wproadie' );
	}

	public function group(): string {
		return 'php';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'code'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'PHP code without the opening <?php tag. Use return to send back a value.', 'wproadie' ),
				),
				'timeout' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_TIMEOUT,
					'default'     => self::DEFAULT_TIMEOUT,
					'description' => __( 'Execution time limit in seconds (1-300), enforced by PHP.', 'wproadie' ),
				),
			),
			'required'             => array( 'code' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$code = self::normalize( (string) ( $input['code'] ?? '' ) );
		if ( '' === trim( $code ) ) {
			return $this->error( 'php_empty', __( 'Give some PHP code.', 'wproadie' ), 400 );
		}

		$syntax = Guardrails::check_php_syntax( '<?php ' . $code );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		if ( self::calls_exit( $code ) ) {
			return $this->error( 'php_exit', __( 'The code calls exit or die, which would end the request without a result. Use return instead. Nothing was run.', 'wproadie' ), 400 );
		}

		$timeout  = max( 1, min( self::MAX_TIMEOUT, (int) ( $input['timeout'] ?? self::DEFAULT_TIMEOUT ) ) );
		$previous = (int) ini_get( 'max_execution_time' );

		$errors  = array();
		$dropped = 0;
		$output  = '';
		$cut     = false;
		$value   = null;
		$thrown  = null;

		$this->register_shutdown();
		self::$running = array(
			'ability' => $this->name(),
			'input'   => $input,
			'started' => microtime( true ),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the snippet's warnings for the result.
		set_error_handler(
			static function ( $errno, $message, $file, $line ) use ( &$errors, &$dropped ) {
				if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Reads the level to honour @.
					return false; // Silenced with @.
				}
				if ( count( $errors ) >= self::MAX_ERRORS ) {
					++$dropped;
					return true;
				}
				$errors[] = array(
					'type'    => self::error_type( (int) $errno ),
					'message' => (string) $message,
					'file'    => self::clean_file( (string) $file ),
					'line'    => (int) $line,
				);
				return true;
			}
		);

		$level = ob_get_level();
		ob_start(
			static function ( $buffer ) use ( &$output, &$cut ) {
				if ( strlen( $output ) < self::MAX_OUTPUT ) {
					$output .= $buffer;
					if ( strlen( $output ) > self::MAX_OUTPUT ) {
						$output = substr( $output, 0, self::MAX_OUTPUT );
						$cut    = true;
					}
				} elseif ( '' !== $buffer ) {
					$cut = true;
				}
				return '';
			},
			4096
		);

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $timeout );
		}

		$started = microtime( true );
		try {
			$value = self::run( $code );
		} catch ( Throwable $e ) {
			$thrown = $e;
		} finally {
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( function_exists( 'set_time_limit' ) ) {
				// Restore the request's own limit (0 = none, e.g. STDIO).
				set_time_limit( $previous );
			}
			// Flush buffers the snippet left open into ours, then close ours.
			while ( ob_get_level() > $level + 1 ) {
				ob_end_flush();
			}
			if ( ob_get_level() > $level ) {
				ob_end_flush();
			}
			restore_error_handler();
			self::$running = null;
		}

		$result                 = array(
			'success'          => null === $thrown,
			'return_value'     => null,
			'return_type'      => self::type_of( $value ),
			'output'           => wp_check_invalid_utf8( $output, true ),
			'output_truncated' => $cut,
			'errors'           => $errors,
			'duration_ms'      => $duration,
		);
		$encoded                = self::encode( $value );
		$result['return_value'] = $encoded['value'];
		if ( $encoded['note'] ) {
			$result['return_note'] = $encoded['note'];
		}
		if ( $dropped ) {
			$result['errors_dropped'] = $dropped;
		}
		if ( $thrown ) {
			$result['exception'] = array(
				'class'   => get_class( $thrown ),
				'message' => $thrown->getMessage(),
				'file'    => self::clean_file( $thrown->getFile() ),
				'line'    => $thrown->getLine(),
			);
		}
		return $result;
	}

	/**
	 * Runs the code in a static closure, so it sees no $this and leaves no
	 * variables behind.
	 *
	 * @return mixed
	 */
	private static function run( string $code ) {
		$closure = static function () use ( $code ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- The purpose of this tool: off by default, staging only, admin only, syntax-checked and logged.
			return eval( $code );
		};
		return $closure();
	}

	/**
	 * Strips an opening <?php tag the AI may include anyway.
	 */
	private static function normalize( string $code ): string {
		$code = ltrim( $code, "\xEF\xBB\xBF \t\r\n" );
		if ( 0 === stripos( $code, '<?php' ) ) {
			$code = substr( $code, 5 );
		} elseif ( 0 === strpos( $code, '<?' ) && 0 !== strpos( $code, '<?xml' ) ) {
			$code = substr( $code, 2 );
		}
		return $code;
	}

	/**
	 * Whether the code contains exit or die. Not a security check (the
	 * tool runs arbitrary code by design); it only avoids a lost result.
	 */
	private static function calls_exit( string $code ): bool {
		foreach ( token_get_all( '<?php ' . $code ) as $token ) {
			if ( is_array( $token ) && T_EXIT === $token[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A JSON-friendly form of the return value.
	 *
	 * @param mixed $value Return value.
	 * @return array{value: mixed, note: string}
	 */
	private static function encode( $value ): array {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return array(
				'value' => $value,
				'note'  => '',
			);
		}
		if ( is_float( $value ) ) {
			return array(
				'value' => is_finite( $value ) ? $value : (string) $value,
				'note'  => '',
			);
		}
		if ( is_string( $value ) ) {
			$cut = strlen( $value ) > self::MAX_OUTPUT;
			return array(
				'value' => wp_check_invalid_utf8( $cut ? substr( $value, 0, self::MAX_OUTPUT ) : $value, true ),
				'note'  => $cut ? __( 'The returned string was cut at 100 KB.', 'wproadie' ) : '',
			);
		}
		if ( is_resource( $value ) ) {
			return array(
				'value' => (string) $value,
				'note'  => __( 'A resource cannot be returned; this is its description.', 'wproadie' ),
			);
		}

		$json = wp_json_encode( $value, JSON_PARTIAL_OUTPUT_ON_ERROR, 64 );
		if ( is_string( $json ) && strlen( $json ) <= self::MAX_OUTPUT ) {
			$decoded = json_decode( $json, true );
			return array(
				'value' => $decoded,
				'note'  => is_object( $value ) ? __( 'The object was converted to JSON (public properties only).', 'wproadie' ) : '',
			);
		}

		// Too large or not encodable: a cut print_r() dump.
		$dump = print_r( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Shows the return value.
		$cut  = strlen( $dump ) > self::MAX_OUTPUT;
		return array(
			'value' => wp_check_invalid_utf8( $cut ? substr( $dump, 0, self::MAX_OUTPUT ) : $dump, true ),
			'note'  => $cut
				? __( 'The value was too large for JSON; this is a print_r() dump cut at 100 KB. Return something smaller.', 'wproadie' )
				: __( 'The value could not be converted to JSON; this is a print_r() dump.', 'wproadie' ),
		);
	}

	/**
	 * @param mixed $value Any value.
	 */
	private static function type_of( $value ): string {
		return is_object( $value ) ? get_class( $value ) : gettype( $value );
	}

	/**
	 * Shows eval()'d code as "snippet" instead of this file's path.
	 */
	private static function clean_file( string $file ): string {
		return false !== strpos( $file, "eval()'d code" ) ? 'snippet' : $file;
	}

	private static function error_type( int $errno ): string {
		$types = array(
			E_WARNING           => 'warning',
			E_NOTICE            => 'notice',
			E_USER_ERROR        => 'user_error',
			E_USER_WARNING      => 'user_warning',
			E_USER_NOTICE       => 'user_notice',
			E_DEPRECATED        => 'deprecated',
			E_USER_DEPRECATED   => 'user_deprecated',
			E_RECOVERABLE_ERROR => 'recoverable_error',
		);
		return $types[ $errno ] ?? 'error_' . $errno;
	}

	/**
	 * Registers, once per request, a shutdown handler that records a
	 * snippet that ended the request (a fatal error such as running out of
	 * memory or time, or redeclaring a function) in the audit log, since
	 * the normal log entry is never written then.
	 */
	private function register_shutdown(): void {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function( array( self::class, 'on_shutdown' ) );
	}

	/**
	 * Shutdown handler, see register_shutdown(). Public for
	 * register_shutdown_function() only.
	 */
	public static function on_shutdown(): void {
		if ( null === self::$running ) {
			return;
		}
		$running       = self::$running;
		self::$running = null;

		$last = error_get_last();
		if ( is_array( $last ) && ( $last['type'] & self::FATAL_ERRORS ) ) {
			$message = sprintf(
				/* translators: 1: error message, 2: file, 3: line number. */
				__( 'The PHP snippet caused a fatal error and the request ended: %1$s in %2$s on line %3$d.', 'wproadie' ),
				$last['message'],
				self::clean_file( (string) $last['file'] ),
				(int) $last['line']
			);
		} else {
			$message = __( 'The PHP snippet ended the request before returning.', 'wproadie' );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last resort: the request is ending.
		error_log( 'WPRoadie run-php: ' . $message );
		try {
			AuditLog::record(
				(string) $running['ability'],
				$running['input'],
				new WP_Error( 'wproadie_php_fatal', $message ),
				(int) round( ( microtime( true ) - (float) $running['started'] ) * 1000 )
			);
		} catch ( Throwable $e ) {
			unset( $e ); // Nothing more can be done during shutdown.
		}
	}
}
