<?php
/**
 * Guardrails used inside abilities: allow-lists and path checks.
 *
 * Everything security-relevant that decides "may the AI touch this?" lives
 * here, so it can be reviewed in one place. Each list can be narrowed or
 * widened per site with a filter.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Guardrails {
	// Options.


	/**
	 * Options the AI may change. Deliberately excludes anything that
	 * changes who can log in or where the site lives: admin_email,
	 * siteurl, home, users_can_register, default_role, active_plugins,
	 * template/stylesheet (use the theme tools).
	 */
	const WRITABLE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'WPLANG',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_public',
		'default_category',
		'default_post_format',
		'default_comment_status',
		'default_ping_status',
		'comment_moderation',
		'comment_previously_approved',
		'comment_registration',
		'require_name_email',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'show_avatars',
		'permalink_structure',
		'category_base',
		'tag_base',
		'site_icon',
		'uploads_use_yearmonth_folders',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
	);

	/**
	 * Options the AI may read but not change.
	 */
	const READ_ONLY_OPTIONS = array(
		'admin_email',
		'siteurl',
		'home',
		'blog_charset',
		'users_can_register',
		'default_role',
		'template',
		'stylesheet',
		'db_version',
	);

	/**
	 * @return string[]
	 */
	public static function writable_options(): array {
		return array_values( (array) apply_filters( 'wproadie_writable_options', self::WRITABLE_OPTIONS ) );
	}

	/**
	 * @return string[]
	 */
	public static function readable_options(): array {
		$readable = array_merge( self::writable_options(), self::READ_ONLY_OPTIONS );
		return array_values( array_unique( (array) apply_filters( 'wproadie_readable_options', $readable ) ) );
	}

	public static function can_read_option( string $name ): bool {
		return in_array( $name, self::readable_options(), true );
	}

	public static function can_write_option( string $name ): bool {
		return in_array( $name, self::writable_options(), true );
	}

	// Files.


	/**
	 * File types the AI may create or overwrite.
	 */
	const WRITABLE_EXTENSIONS = array( 'php', 'css', 'scss', 'js', 'json', 'html', 'htm', 'txt', 'md', 'xml', 'svg', 'twig' );

	/**
	 * Basenames that are never read or written, wherever they are.
	 */
	const SECRET_FILE_PATTERNS = array( 'wp-config*.php', '.env*', '*.pem', '*.key', '.htpasswd', 'auth.json', '*.sql', '*.sql.gz' );

	/**
	 * WPRoadie's own folder under wp-content, for notes, exports and
	 * file backups. Never PHP: it is web-reachable and not loaded by
	 * WordPress, so PHP there would only be a way to bypass the child
	 * theme's syntax check.
	 */
	public static function own_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'wproadie';
	}

	/**
	 * Creates the own folder with files that stop directory listing and,
	 * on Apache, direct access.
	 */
	public static function ensure_own_dir(): bool {
		$dir = self::own_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return true;
	}

	/**
	 * Folders the AI may read from: themes, plugins, must-use plugins and
	 * WPRoadie's own folder. Not WordPress core, not wp-config.php, not
	 * uploads (use the media tools).
	 *
	 * @return string[] Absolute paths without trailing slashes.
	 */
	public static function readable_roots(): array {
		$roots = array(
			get_theme_root(),
			WP_PLUGIN_DIR,
			WPMU_PLUGIN_DIR,
			self::own_dir(),
		);
		return self::normalize_roots( (array) apply_filters( 'wproadie_readable_roots', $roots ) );
	}

	/**
	 * Folders the AI may write to: the active child theme (never a parent
	 * or third-party theme, whose updates would wipe the change) and
	 * WPRoadie's own folder.
	 *
	 * @return string[] Absolute paths without trailing slashes.
	 */
	public static function writable_roots(): array {
		$roots = array( self::own_dir() );
		if ( is_child_theme() ) {
			$roots[] = get_stylesheet_directory();
		}
		return self::normalize_roots( (array) apply_filters( 'wproadie_writable_roots', $roots ) );
	}

	/**
	 * Resolves a path the AI gave us to a real absolute path inside an
	 * allowed root, or explains why not.
	 *
	 * Relative paths are relative to wp-content. Symlinks are resolved
	 * before the root check, so a link cannot point outside the roots.
	 *
	 * @param string $path     Path from the AI.
	 * @param bool   $for_write Whether the caller wants to write.
	 * @return string|WP_Error Absolute path.
	 */
	public static function resolve_path( string $path, bool $for_write ) {
		$path = trim( wp_normalize_path( $path ) );
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return self::path_error( __( 'Give a file path.', 'wproadie' ) );
		}

		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( ! path_is_absolute( $path ) ) {
			$path = $content . '/' . ltrim( $path, '/' );
		}

		$segments = explode( '/', $path );
		if ( in_array( '..', $segments, true ) ) {
			return self::path_error( __( 'Paths may not contain "..".', 'wproadie' ) );
		}

		$real = self::real_path( $path );
		if ( null === $real ) {
			return self::path_error( __( 'That folder does not exist.', 'wproadie' ) );
		}

		$roots = $for_write ? self::writable_roots() : self::readable_roots();
		$root  = self::root_for( $real, $roots );
		if ( null === $root ) {
			return self::path_error(
				$for_write
					? __( 'Writes are only allowed in the active child theme and wp-content/wproadie.', 'wproadie' )
					: __( 'Reads are only allowed in themes, plugins, must-use plugins and wp-content/wproadie.', 'wproadie' )
			);
		}

		if ( self::is_secret( $real ) ) {
			return self::path_error( __( 'That file may hold secrets and is off limits.', 'wproadie' ) );
		}

		if ( $for_write ) {
			$basename = basename( $real );
			if ( $real === $root ) {
				return self::path_error( __( 'Give a file path, not a folder.', 'wproadie' ) );
			}
			if ( 0 === strpos( $basename, '.' ) ) {
				return self::path_error( __( 'Hidden files (starting with a dot) cannot be written.', 'wproadie' ) );
			}
			$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, self::WRITABLE_EXTENSIONS, true ) ) {
				return self::path_error(
					sprintf(
						/* translators: %s: list of file extensions. */
						__( 'Only these file types can be written: %s.', 'wproadie' ),
						implode( ', ', self::WRITABLE_EXTENSIONS )
					)
				);
			}
			$own = untrailingslashit( wp_normalize_path( self::own_dir() ) );
			if ( 'php' === $extension && ( $real === $own || 0 === strpos( $real, $own . '/' ) ) ) {
				return self::path_error( __( 'PHP files can only be written to the child theme.', 'wproadie' ) );
			}
		}

		return $real;
	}

	/**
	 * Checks PHP source for syntax errors without running it.
	 *
	 * @return true|WP_Error
	 */
	public static function check_php_syntax( string $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error(
				'wproadie_php_syntax',
				sprintf(
					/* translators: 1: error message, 2: line number. */
					__( 'PHP syntax error: %1$s on line %2$d. Nothing was written.', 'wproadie' ),
					$e->getMessage(),
					$e->getLine()
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Real path for an existing path, or for a new file: the real path of
	 * its nearest existing ancestor plus the remaining segments.
	 */
	private static function real_path( string $path ): ?string {
		$real = realpath( $path );
		if ( false !== $real ) {
			return wp_normalize_path( $real );
		}

		$missing = array();
		$current = $path;
		while ( false === realpath( $current ) ) {
			$missing[] = basename( $current );
			$parent    = dirname( $current );
			if ( $parent === $current ) {
				return null;
			}
			$current = $parent;
		}

		$base = wp_normalize_path( (string) realpath( $current ) );
		if ( ! is_dir( $base ) ) {
			return null;
		}
		return untrailingslashit( $base ) . '/' . implode( '/', array_reverse( $missing ) );
	}

	/**
	 * @param string   $real  Normalized real path.
	 * @param string[] $roots Normalized roots.
	 */
	private static function root_for( string $real, array $roots ): ?string {
		foreach ( $roots as $root ) {
			if ( $real === $root || 0 === strpos( $real, $root . '/' ) ) {
				return $root;
			}
		}
		return null;
	}

	private static function is_secret( string $path ): bool {
		$basename = basename( $path );
		foreach ( self::SECRET_FILE_PATTERNS as $pattern ) {
			if ( fnmatch( $pattern, $basename, FNM_CASEFOLD ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $roots Raw root paths.
	 * @return string[]
	 */
	private static function normalize_roots( array $roots ): array {
		$normalized = array();
		foreach ( $roots as $root ) {
			if ( ! is_string( $root ) || '' === $root ) {
				continue;
			}
			$real = realpath( $root );
			// A root that does not exist yet (e.g. wp-content/wproadie before
			// first use) still counts, by its literal path.
			$normalized[] = untrailingslashit( wp_normalize_path( false !== $real ? $real : $root ) );
		}
		return array_values( array_unique( $normalized ) );
	}

	private static function path_error( string $message ): WP_Error {
		return new WP_Error( 'wproadie_path_not_allowed', $message, array( 'status' => 403 ) );
	}

	// Outbound URLs.


	/**
	 * Whether a URL the AI gave us may be fetched by the server: it passes
	 * wp_http_validate_url() (http(s), no credentials, allowed ports, no
	 * private or loopback IPs), and every address the host resolves to is
	 * public, which also rules out link-local (169.254.0.0/16, e.g. cloud
	 * metadata endpoints), reserved and carrier-grade NAT ranges, which
	 * core misses. The site's own host stays allowed, as in core. Hosts
	 * with only IPv6 addresses are refused. DNS can still change between
	 * this check and the fetch (rebinding); re-check redirects too.
	 */
	public static function is_public_url( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return true;
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
			$long = ip2long( $ip );
			if ( false !== $long && 0x64400000 === ( $long & 0xFFC00000 ) ) {
				return false; // 100.64.0.0/10.
			}
		}
		return true;
	}

	// Plugins.


	/**
	 * Plugins the AI may not deactivate, delete or update: WPRoadie itself
	 * and the MCP Adapter it runs on. Changing them would cut the AI off
	 * mid-task or bypass our pinned adapter version.
	 *
	 * @return string[] Plugin files, e.g. "mcp-adapter/mcp-adapter.php".
	 */
	public static function protected_plugins(): array {
		$protected = array( plugin_basename( WPROADIE_FILE ), 'mcp-adapter/mcp-adapter.php' );
		return array_values( (array) apply_filters( 'wproadie_protected_plugins', $protected ) );
	}

	public static function is_protected_plugin( string $plugin_file ): bool {
		return in_array( $plugin_file, self::protected_plugins(), true );
	}

	// WP-CLI.


	/**
	 * Allowed `wp` commands, as leading command words. A command is allowed
	 * when its words start with one of these. Notably absent: eval,
	 * eval-file, shell, db query/export/import/reset, config, user create,
	 * plugin/theme install and anything that has its own WPRoadie tool.
	 */
	const CLI_ALLOWLIST = array(
		'cache flush',
		'cache type',
		'rewrite flush',
		'rewrite list',
		'rewrite structure',
		'transient get',
		'transient delete',
		'transient list',
		'cron event list',
		'cron event run',
		'cron schedule list',
		'cron test',
		'core version',
		'core check-update',
		'core verify-checksums',
		'plugin list',
		'plugin get',
		'plugin status',
		'plugin verify-checksums',
		'plugin auto-updates status',
		'theme list',
		'theme get',
		'theme status',
		'role list',
		'cap list',
		'post list',
		'post get',
		'post meta list',
		'post-type list',
		'taxonomy list',
		'term list',
		'comment count',
		'comment list',
		'menu list',
		'menu item list',
		'menu location list',
		'widget list',
		'sidebar list',
		'option get',
		'media regenerate',
		'media image-size',
		'db check',
		'db size',
		'db tables',
		'search-replace',
		'language core list',
		'language plugin list',
		'language theme list',
		'maintenance-mode status',
	);

	/**
	 * Global flags that would change what WP-CLI runs or as whom.
	 */
	const CLI_BLOCKED_FLAGS = array( '--exec', '--require', '--path', '--ssh', '--http', '--url', '--user', '--prompt', '--skip-plugins', '--skip-themes', '--context' );

	/**
	 * @return string[]
	 */
	public static function cli_allowlist(): array {
		return array_values( (array) apply_filters( 'wproadie_cli_allowlist', self::CLI_ALLOWLIST ) );
	}

	/**
	 * Splits a command line into arguments, honouring single and double
	 * quotes and backslash escapes, the way a POSIX shell would for plain
	 * words. No expansion of any kind happens: the arguments are passed to
	 * proc_open as an array, never through a shell.
	 *
	 * @return string[]|WP_Error
	 */
	public static function split_command( string $command ) {
		$command = trim( $command );
		if ( preg_match( '/[\x00-\x08\x0A-\x1F]/', $command ) ) {
			return self::cli_error( __( 'Commands must be a single line.', 'wproadie' ) );
		}
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		$args    = array();
		$current = '';
		$quote   = '';
		$started = false;
		$length  = strlen( $command );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $command[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				} elseif ( '\\' === $char && '"' === $quote && $i + 1 < $length ) {
					$current .= $command[ ++$i ];
				} else {
					$current .= $char;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$started = true;
			} elseif ( '\\' === $char && $i + 1 < $length ) {
				$current .= $command[ ++$i ];
				$started  = true;
			} elseif ( ' ' === $char || "\t" === $char ) {
				if ( $started ) {
					$args[]  = $current;
					$current = '';
					$started = false;
				}
			} else {
				$current .= $char;
				$started  = true;
			}
		}

		if ( '' !== $quote ) {
			return self::cli_error( __( 'Unclosed quote in command.', 'wproadie' ) );
		}
		if ( $started ) {
			$args[] = $current;
		}
		return $args;
	}

	/**
	 * Checks split arguments against the allow-list and blocked flags.
	 *
	 * @param string[] $args Arguments without the leading "wp".
	 * @return true|WP_Error
	 */
	public static function check_cli( array $args ) {
		if ( ! $args ) {
			return self::cli_error( __( 'Give a wp command, e.g. "cache flush".', 'wproadie' ) );
		}

		foreach ( $args as $arg ) {
			$flag = strtolower( explode( '=', $arg, 2 )[0] );
			if ( in_array( $flag, self::CLI_BLOCKED_FLAGS, true ) ) {
				return self::cli_error(
					/* translators: %s: flag name. */
					sprintf( __( 'The %s flag is not allowed.', 'wproadie' ), $flag )
				);
			}
		}

		// Command words are the leading arguments that are not flags.
		$words = array();
		foreach ( $args as $arg ) {
			if ( 0 === strpos( $arg, '-' ) ) {
				break;
			}
			$words[] = strtolower( $arg );
		}

		$allowed = false;
		foreach ( self::cli_allowlist() as $entry ) {
			$prefix = explode( ' ', $entry );
			if ( array_slice( $words, 0, count( $prefix ) ) === $prefix ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return self::cli_error(
				sprintf(
					/* translators: %s: list of commands. */
					__( 'That command is not on the WPRoadie allow-list. Allowed: %s.', 'wproadie' ),
					implode( ', ', self::cli_allowlist() )
				)
			);
		}

		// search-replace rewrites the database wholesale; only previews are
		// allowed. Use the database write tool for targeted changes. A later
		// --no-dry-run or --dry-run=<value> would override the preview.
		if ( 'search-replace' === $words[0] && ! apply_filters( 'wproadie_allow_search_replace', false ) ) {
			$overrides = preg_grep( '/^--(no-dry-run|dry-run=)/i', $args );
			if ( ! in_array( '--dry-run', $args, true ) || $overrides ) {
				return self::cli_error( __( 'search-replace is only allowed with --dry-run.', 'wproadie' ) );
			}
		}

		// Options can hold secrets (salts, API keys): only the same
		// allow-list the settings tools read is available here.
		if ( array( 'option', 'get' ) === array_slice( $words, 0, 2 ) ) {
			$name = $words[2] ?? '';
			if ( ! self::can_read_option( $name ) || count( $words ) > 3 ) {
				return self::cli_error( __( 'option get only works for the options the settings tools may read (see get-options).', 'wproadie' ) );
			}
		}

		// Running every cron hook at once is a blunt instrument; name the
		// hook or use --due-now.
		if ( array( 'cron', 'event', 'run' ) === array_slice( $words, 0, 3 ) && preg_grep( '/^--all$/i', $args ) ) {
			return self::cli_error( __( 'cron event run --all is not allowed. Name the hook, or use --due-now.', 'wproadie' ) );
		}

		return true;
	}

	private static function cli_error( string $message ): WP_Error {
		return new WP_Error( 'wproadie_cli_not_allowed', $message, array( 'status' => 403 ) );
	}

	// Database.


	/**
	 * Checks that SQL is a single read-only statement. Works on the lexed
	 * statement (see lex_sql()), so keywords split by comments or hidden in
	 * string literals are seen correctly. The database tool also runs reads
	 * inside a read-only transaction where the server supports it.
	 *
	 * @return true|WP_Error
	 */
	public static function check_read_query( string $sql ) {
		$lexed = self::lex_sql( $sql );
		if ( is_wp_error( $lexed ) ) {
			return $lexed;
		}
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $lexed ) ) {
			return self::sql_error( __( 'Only SELECT, SHOW, DESCRIBE and EXPLAIN are allowed. Use the database write tool for changes.', 'wproadie' ) );
		}
		if ( preg_match( '/\bFOR\s+(UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $lexed ) ) {
			return self::sql_error( __( 'Locking reads are not allowed.', 'wproadie' ) );
		}
		// EXPLAIN ANALYZE executes the statement it explains.
		if ( preg_match( '/^(EXPLAIN|DESCRIBE|DESC)\b/i', $lexed ) && preg_match( '/\bANALYZE\b/i', $lexed ) && preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $lexed ) ) {
			return self::sql_error( __( 'EXPLAIN ANALYZE runs the statement; it is only allowed for SELECT.', 'wproadie' ) );
		}
		return self::check_sql_common( $lexed );
	}

	/**
	 * Checks that SQL is a single statement of a kind the write tool
	 * allows: data changes, never schema or server changes.
	 *
	 * @return true|WP_Error
	 */
	public static function check_write_query( string $sql ) {
		$lexed = self::lex_sql( $sql );
		if ( is_wp_error( $lexed ) ) {
			return $lexed;
		}
		if ( ! preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $lexed ) ) {
			return self::sql_error( __( 'Only INSERT, UPDATE, DELETE and REPLACE are allowed. Schema changes (CREATE, ALTER, DROP, TRUNCATE) are not.', 'wproadie' ) );
		}
		// The word WHERE inside a string or comment does not count.
		if ( preg_match( '/^(UPDATE|DELETE)\b/i', $lexed ) && ! preg_match( '/\bWHERE\b/i', $lexed ) ) {
			return self::sql_error( __( 'UPDATE and DELETE need a WHERE clause.', 'wproadie' ) );
		}
		return self::check_sql_common( $lexed );
	}

	/**
	 * @param string $lexed Output of lex_sql().
	 * @return true|WP_Error
	 */
	private static function check_sql_common( string $lexed ) {
		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b|\bLOAD\s+DATA\b|\b(LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK)\s*\(/i', $lexed ) ) {
			return self::sql_error( __( 'That query uses a function or clause that is not allowed.', 'wproadie' ) );
		}
		return true;
	}

	/**
	 * Reduces a statement to its structure: comments become a space,
	 * string literals become '' and quoted identifiers become `x`, so
	 * keyword checks cannot be fooled by either. Refuses executable
	 * comments (MySQL "/*!", MariaDB "/*M!"), unterminated strings or
	 * comments, and more than one statement. Assumes backslash escapes,
	 * which WordPress's own escaping also relies on.
	 *
	 * @return string|WP_Error
	 */
	public static function lex_sql( string $sql ) {
		$out    = '';
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$char = $sql[ $i ];
			$next = $i + 1 < $length ? $sql[ $i + 1 ] : '';

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$end = self::closing_quote( $sql, $i, $char );
				if ( null === $end ) {
					return self::sql_error( __( 'Unterminated string or identifier.', 'wproadie' ) );
				}
				$out .= '`' === $char ? '`x`' : "''";
				$i    = $end + 1;
				continue;
			}
			if ( '/' === $char && '*' === $next ) {
				$rest = substr( $sql, $i + 2, 2 );
				if ( '!' === substr( $rest, 0, 1 ) || 'm!' === strtolower( $rest ) ) {
					return self::sql_error( __( 'Executable comments (/*! ... */, /*M! ... */) are not allowed.', 'wproadie' ) );
				}
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return self::sql_error( __( 'Unterminated comment.', 'wproadie' ) );
				}
				$out .= ' ';
				$i    = $end + 2;
				continue;
			}
			$dash_comment = '-' === $char && '-' === $next && ( $i + 2 >= $length || ctype_space( $sql[ $i + 2 ] ) );
			if ( '#' === $char || $dash_comment ) {
				$end  = strpos( $sql, "\n", $i );
				$out .= ' ';
				$i    = false === $end ? $length : $end + 1;
				continue;
			}
			$out .= $char;
			++$i;
		}

		$out = rtrim( trim( $out ), "; \t\n\r" );
		if ( '' === $out ) {
			return self::sql_error( __( 'Give a SQL query.', 'wproadie' ) );
		}
		if ( false !== strpos( $out, ';' ) ) {
			return self::sql_error( __( 'Only one statement is allowed.', 'wproadie' ) );
		}
		return $out;
	}

	/**
	 * Position of the quote that closes the one at $start, or null.
	 * Handles backslash escapes (not in identifiers) and doubled quotes.
	 */
	private static function closing_quote( string $sql, int $start, string $quote ): ?int {
		$length = strlen( $sql );
		for ( $i = $start + 1; $i < $length; $i++ ) {
			if ( '\\' === $sql[ $i ] && '`' !== $quote ) {
				++$i;
				continue;
			}
			if ( $sql[ $i ] === $quote ) {
				if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
					++$i;
					continue;
				}
				return $i;
			}
		}
		return null;
	}

	private static function sql_error( string $message ): WP_Error {
		return new WP_Error( 'wproadie_sql_not_allowed', $message, array( 'status' => 403 ) );
	}
}
