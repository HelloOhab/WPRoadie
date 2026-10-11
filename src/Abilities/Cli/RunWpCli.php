<?php
/**
 * Runs an allow-listed WP-CLI command, without a shell.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Cli;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RunWpCli extends AbilityBase {

	const DEFAULT_TIMEOUT = 60;
	const MAX_TIMEOUT     = 300;

	/**
	 * Most bytes kept from each of stdout and stderr.
	 */
	const MAX_OUTPUT = 102400;

	/**
	 * Process functions the tool needs. PHP before 8.0 keeps disabled
	 * functions defined, so disable_functions is checked as well.
	 */
	const REQUIRED_FUNCTIONS = array( 'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close' );

	/**
	 * Detection result for this request, see detect().
	 *
	 * @var array|null
	 */
	private static $detected = null;

	public function slug(): string {
		return 'run-wp-cli';
	}

	public function label(): string {
		return __( 'Run a WP-CLI command', 'wproadie' );
	}

	public function description(): string {
		return sprintf(
			/* translators: %s: list of allowed commands. */
			__( 'Runs one allow-listed WP-CLI command on this site as the connected user and returns its exit code, standard output and standard error. Give the command with or without the leading "wp", e.g. "cache flush" or "plugin list --format=json"; prefer --format=json where a command supports it. Use it for maintenance the other tools do not cover (flushing caches and rewrite rules, cron, checksums, search-replace previews). Allowed commands: %s. Not allowed: eval, shell, db query/import/export, config, and the global flags --exec, --require, --path, --url, --user, --ssh, --http, --skip-plugins, --skip-themes, --context and --prompt. search-replace only runs with --dry-run. The command runs without a shell (no pipes, redirects or variables) with --path, --user and --no-color set by WPRoadie (and --allow-root when the server runs as root). It is stopped after timeout seconds; output over 100 KB per stream is cut. Changes made by a command cannot be undone with WPRoadie undo.', 'wproadie' ),
			implode( ', ', Guardrails::cli_allowlist() )
		);
	}

	public function group(): string {
		return 'cli';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'command' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The WP-CLI command, e.g. "rewrite flush" or "wp plugin list --format=json". Quote arguments that contain spaces.', 'wproadie' ),
				),
				'timeout' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_TIMEOUT,
					'default'     => self::DEFAULT_TIMEOUT,
					'description' => __( 'Seconds before the command is stopped (1-300).', 'wproadie' ),
				),
			),
			'required'             => array( 'command' ),
			'additionalProperties' => false,
		);
	}

	public function is_available(): bool {
		return '' === self::detect()['reason'];
	}

	public function unavailable_reason(): string {
		return self::detect()['reason'];
	}

	protected function execute( array $input ) {
		$detected = self::detect();
		if ( '' !== $detected['reason'] ) {
			return $this->error( 'cli_unavailable', $detected['reason'], 503 );
		}

		$args = Guardrails::split_command( (string) ( $input['command'] ?? '' ) );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$allowed = Guardrails::check_cli( $args );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$timeout = max( 1, min( self::MAX_TIMEOUT, (int) ( $input['timeout'] ?? self::DEFAULT_TIMEOUT ) ) );

		// Global flags first: WPRoadie decides where and as whom the command
		// runs. --user is blocked for the AI, but set here on purpose so the
		// command runs as the connected user.
		$globals = array(
			'--path=' . untrailingslashit( ABSPATH ),
			'--user=' . get_current_user_id(),
			'--no-color',
		);
		if ( self::running_as_root() ) {
			// WP-CLI refuses to run as root without it.
			$globals[] = '--allow-root';
		}
		$command = array_merge( $detected['prefix'], $globals, $args );

		$result = self::run_process( $command, $timeout, self::environment(), ABSPATH );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$notes = array();
		if ( $result['timed_out'] ) {
			/* translators: %d: seconds. */
			$notes[] = sprintf( __( 'The command was stopped after %d seconds. It may have made some changes before it was stopped.', 'wproadie' ), $timeout );
		}
		if ( $result['stdout_truncated'] || $result['stderr_truncated'] ) {
			$notes[] = __( 'Output was cut at 100 KB. Narrow the command (e.g. --fields, --format=count, filters) to see less.', 'wproadie' );
		}

		return array(
			'command'          => 'wp ' . self::display( $args ),
			'exit_code'        => $result['exit_code'],
			'success'          => 0 === $result['exit_code'] && ! $result['timed_out'],
			'stdout'           => $result['stdout'],
			'stderr'           => $result['stderr'],
			'stdout_truncated' => $result['stdout_truncated'],
			'stderr_truncated' => $result['stderr_truncated'],
			'timed_out'        => $result['timed_out'],
			'duration_ms'      => $result['duration_ms'],
			'notes'            => $notes,
		);
	}

	/**
	 * Runs a process from an argument array (no shell), with a timeout and
	 * capped output. Public for tests.
	 *
	 * @param string[]    $command Program and arguments.
	 * @param int         $timeout Seconds.
	 * @param array       $env     Environment for the process.
	 * @param string|null $cwd     Working directory.
	 * @return array|\WP_Error
	 */
	public static function run_process( array $command, int $timeout, array $env, ?string $cwd = null ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$started     = microtime( true );

		// The array form runs the program directly, never through a shell.
		$argv    = array_values( array_map( 'strval', $command ) );
		$process = proc_open( $argv, $descriptors, $pipes, $cwd, $env ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The purpose of this tool; availability is checked in detect().
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'wproadie_cli_failed', __( 'Could not start WP-CLI.', 'wproadie' ), array( 'status' => 500 ) );
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output    = array(
			1 => '',
			2 => '',
		);
		$truncated = array(
			1 => false,
			2 => false,
		);
		$open      = array(
			1 => $pipes[1],
			2 => $pipes[2],
		);
		$deadline  = $started + $timeout;
		$timed_out = false;
		$exit_code = null;

		while ( $open ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				$timed_out = true;
				break;
			}
			$read   = array_values( $open );
			$write  = null;
			$except = null;
			$ready  = @stream_select( $read, $write, $except, 0, (int) min( 200000, $remaining * 1000000 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EINTR on signals.
			if ( false === $ready ) {
				break;
			}
			foreach ( $read as $stream ) {
				$key   = array_search( $stream, $open, true );
				$chunk = fread( $stream, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Process pipe.
				if ( false === $chunk || ( '' === $chunk && feof( $stream ) ) ) {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
					unset( $open[ $key ] );
					continue;
				}
				// Keep draining past the cap so the process never blocks on
				// a full pipe; just stop storing.
				if ( strlen( $output[ $key ] ) < self::MAX_OUTPUT ) {
					$output[ $key ] .= $chunk;
					if ( strlen( $output[ $key ] ) > self::MAX_OUTPUT ) {
						$output[ $key ]    = substr( $output[ $key ], 0, self::MAX_OUTPUT );
						$truncated[ $key ] = true;
					}
				} elseif ( '' !== $chunk ) {
					$truncated[ $key ] = true;
				}
			}
		}

		if ( $timed_out ) {
			self::terminate( $process );
		}
		foreach ( $open as $stream ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		}

		// proc_close() only reports the exit code if proc_get_status() has
		// not already seen the process exit.
		$status = proc_get_status( $process );
		if ( ! $status['running'] && -1 !== $status['exitcode'] ) {
			$exit_code = (int) $status['exitcode'];
		}
		$closed = proc_close( $process );
		if ( null === $exit_code ) {
			$exit_code = $timed_out ? -1 : (int) $closed;
		}

		return array(
			'exit_code'        => $exit_code,
			'stdout'           => self::utf8( $output[1] ),
			'stderr'           => self::utf8( $output[2] ),
			'stdout_truncated' => $truncated[1],
			'stderr_truncated' => $truncated[2],
			'timed_out'        => $timed_out,
			'duration_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * Stops a process: SIGTERM, then SIGKILL if it is still running.
	 *
	 * @param resource $process Process handle.
	 */
	private static function terminate( $process ): void {
		proc_terminate( $process, 15 );
		$until = microtime( true ) + 2;
		while ( microtime( true ) < $until ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				return;
			}
			usleep( 50000 );
		}
		proc_terminate( $process, 9 );
	}

	/**
	 * Finds WP-CLI and the PHP binary to run it with, once per request.
	 *
	 * @return array{prefix: string[], binary: string, reason: string}
	 */
	public static function detect(): array {
		if ( null !== self::$detected ) {
			return self::$detected;
		}

		$missing  = array();
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( self::REQUIRED_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) || in_array( $function, $disabled, true ) ) {
				$missing[] = $function;
			}
		}
		if ( $missing ) {
			return self::remember(
				array(),
				'',
				sprintf(
					/* translators: %s: list of PHP functions. */
					__( 'PHP cannot start processes on this server: %s is disabled or missing.', 'wproadie' ),
					implode( ', ', $missing )
				)
			);
		}

		$binary = self::find_wp_cli();
		if ( is_wp_error( $binary ) ) {
			return self::remember( array(), '', $binary->get_error_message() );
		}

		if ( ! self::is_php_script( $binary ) ) {
			return self::remember( array( $binary ), $binary, '' );
		}

		$php = self::find_php();
		if ( '' === $php ) {
			return self::remember( array(), $binary, __( 'Found WP-CLI but no PHP command-line binary to run it with. Define WPROADIE_PHP_BINARY with its path.', 'wproadie' ) );
		}
		return self::remember( array( $php, $binary ), $binary, '' );
	}

	/**
	 * Forgets the detection result, e.g. after changing the filter in a
	 * test.
	 */
	public static function reset_detection(): void {
		self::$detected = null;
	}

	/**
	 * @param string[] $prefix Program and leading arguments.
	 */
	private static function remember( array $prefix, string $binary, string $reason ): array {
		self::$detected = array(
			'prefix' => $prefix,
			'binary' => $binary,
			'reason' => $reason,
		);
		return self::$detected;
	}

	/**
	 * WP-CLI's path: the WPROADIE_WP_CLI constant, the wproadie_wp_cli_path
	 * filter, the binary running this request when under WP-CLI, or `wp` /
	 * `wp-cli.phar` on the PATH.
	 *
	 * @return string|\WP_Error
	 */
	private static function find_wp_cli() {
		$configured = defined( 'WPROADIE_WP_CLI' ) ? (string) WPROADIE_WP_CLI : '';
		$configured = (string) apply_filters( 'wproadie_wp_cli_path', $configured );
		if ( '' !== $configured ) {
			if ( is_file( $configured ) && is_readable( $configured ) ) {
				return (string) realpath( $configured );
			}
			return new \WP_Error(
				'wproadie_cli_unavailable',
				/* translators: %s: file path. */
				sprintf( __( 'The configured WP-CLI path %s is not a readable file.', 'wproadie' ), $configured )
			);
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$running = self::running_wp_cli();
			if ( '' !== $running ) {
				return $running;
			}
		}

		foreach ( self::search_dirs() as $dir ) {
			foreach ( array( 'wp', 'wp-cli.phar', 'wp-cli' ) as $name ) {
				$candidate = $dir . DIRECTORY_SEPARATOR . $name;
				if ( is_file( $candidate ) && is_readable( $candidate ) && ( is_executable( $candidate ) || self::is_php_script( $candidate ) ) ) {
					return (string) realpath( $candidate );
				}
			}
		}

		return new \WP_Error( 'wproadie_cli_unavailable', __( 'WP-CLI was not found on the PATH. Install it, or define WPROADIE_WP_CLI with the path to the wp binary or wp-cli.phar.', 'wproadie' ) );
	}

	/**
	 * The WP-CLI binary running this request, if any.
	 */
	private static function running_wp_cli(): string {
		if ( class_exists( 'Phar' ) ) {
			$phar = \Phar::running( false );
			if ( '' !== $phar && is_file( $phar ) ) {
				return (string) realpath( $phar );
			}
		}
		$argv0 = isset( $_SERVER['argv'][0] ) ? (string) $_SERVER['argv'][0] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CLI argv, a path.
		if ( '' === $argv0 ) {
			return '';
		}
		$real = realpath( $argv0 );
		if ( false === $real || ! is_file( $real ) ) {
			return '';
		}
		// A Composer install runs vendor/bin/wp or wp-cli/bin/wp; make sure
		// this is WP-CLI and not some other script that loaded WordPress.
		$base = strtolower( basename( $argv0 ) );
		if ( in_array( $base, array( 'wp', 'wp-cli', 'wp-cli.phar', 'wp.bat' ), true ) || 'wp' === strtolower( basename( $real ) ) ) {
			return $real;
		}
		return '';
	}

	/**
	 * @return string[]
	 */
	private static function search_dirs(): array {
		$path = (string) getenv( 'PATH' );
		$dirs = '' !== $path ? explode( PATH_SEPARATOR, $path ) : array();
		// Web servers often run with a minimal PATH.
		$dirs = array_merge( $dirs, array( '/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin' ) );
		return array_values(
			array_unique(
				array_filter(
					$dirs,
					static function ( $dir ) {
						return '' !== $dir && @is_dir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
					}
				)
			)
		);
	}

	/**
	 * Whether a file must be run with PHP: a .phar, or a script whose
	 * first line is a PHP shebang or opening tag.
	 */
	private static function is_php_script( string $file ): bool {
		if ( '.phar' === strtolower( substr( $file, -5 ) ) ) {
			return true;
		}
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Peeks at the first line.
		if ( ! $handle ) {
			return false;
		}
		$head = (string) fread( $handle, 128 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$first = strtok( $head, "\n" );
		$first = false === $first ? '' : $first;
		if ( 0 === strpos( $first, '<?php' ) ) {
			return true;
		}
		return 0 === strpos( $first, '#!' ) && (bool) preg_match( '/\bphp[\d.]*\s*$/', trim( $first ) );
	}

	/**
	 * The PHP CLI binary. In web requests PHP_BINARY is often php-fpm or
	 * empty, which cannot run scripts.
	 */
	private static function find_php(): string {
		$candidates = array();
		if ( defined( 'WPROADIE_PHP_BINARY' ) ) {
			$candidates[] = (string) WPROADIE_PHP_BINARY;
		}
		if ( '' !== PHP_BINARY && 'cli' === PHP_SAPI ) {
			$candidates[] = PHP_BINARY;
		}
		if ( '' !== PHP_BINARY && preg_match( '/^php[\d.]*(\.exe)?$/i', basename( PHP_BINARY ) ) ) {
			$candidates[] = PHP_BINARY;
		}
		$candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
		$candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( self::search_dirs() as $dir ) {
			$candidates[] = $dir . DIRECTORY_SEPARATOR . 'php';
		}
		$candidates = (array) apply_filters( 'wproadie_wp_cli_php_binary', $candidates );
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && @is_file( $candidate ) && @is_executable( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
				return $candidate;
			}
		}
		return '';
	}

	private static function running_as_root(): bool {
		return function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
	}

	/**
	 * A minimal environment, so the web server's variables (which may hold
	 * credentials) never reach the command.
	 */
	private static function environment(): array {
		$path = (string) getenv( 'PATH' );
		$home = (string) getenv( 'HOME' );
		if ( '' === $home && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$user = posix_getpwuid( posix_geteuid() );
			$home = is_array( $user ) && ! empty( $user['dir'] ) ? (string) $user['dir'] : '';
		}
		if ( '' === $home || ! @is_dir( $home ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
			$home = get_temp_dir();
		}
		$env = array(
			'PATH'                             => '' !== $path ? $path : '/usr/local/bin:/usr/bin:/bin',
			'HOME'                             => untrailingslashit( $home ),
			'LANG'                             => 'C.UTF-8',
			'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1',
		);
		foreach ( array( 'TMPDIR', 'SystemRoot', 'TEMP', 'TMP' ) as $name ) {
			$value = getenv( $name );
			if ( is_string( $value ) && '' !== $value ) {
				$env[ $name ] = $value;
			}
		}
		return $env;
	}

	/**
	 * The arguments as a readable command line.
	 *
	 * @param string[] $args Arguments.
	 */
	private static function display( array $args ): string {
		return implode(
			' ',
			array_map(
				static function ( $arg ) {
					return preg_match( '/^[A-Za-z0-9_\-=.,:\/@%+]+$/', $arg ) ? $arg : "'" . str_replace( "'", "'\\''", $arg ) . "'";
				},
				$args
			)
		);
	}

	private static function utf8( string $text ): string {
		return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $text, true ) : $text;
	}
}
