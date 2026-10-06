<?php
/**
 * Transport and filesystem safety primitives.
 *
 * @package In_Synced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure transport, SQL and path safety primitives. */
final class In_Synced_Safety {

	public static function signature( string $method, string $route, string $timestamp, string $nonce, string $body, string $secret ): string {
		return 'v2=' . hash_hmac( 'sha256', implode( "\n", array( strtoupper( $method ), $route, $timestamp, $nonce, hash( 'sha256', $body ) ) ), $secret );
	}

	/**
	 * Encode strings before JSON can alter invalid UTF-8 or BLOB bytes.
	 *
	 * @param array $rows Database rows.
	 */
	public static function encode_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			foreach ( $row as &$value ) {
				if ( is_string( $value ) ) {
					$value = array(
						'encoding' => 'base64',
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Lossless transport of database/file bytes, never evaluation or execution of encoded content.
						'data'     => base64_encode( $value ),
					);
				}
			}
			unset( $value );
		}
		unset( $row );
		return $rows;
	}

	public static function decode_rows( array $rows ) {
		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) || ! $row ) {
				return new WP_Error( 'in_synced_bad_rows', __( 'Each database row must be a nonempty object.', 'in-synced' ), array( 'status' => 400 ) );
			}
			foreach ( $row as $column => &$value ) {
				if ( ! is_string( $column ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $column ) ) {
					return new WP_Error( 'in_synced_bad_rows', __( 'Invalid database column name.', 'in-synced' ), array( 'status' => 400 ) );
				}
				if ( is_array( $value ) && array_keys( $value ) === array( 'encoding', 'data' ) && $value['encoding'] === 'base64' && is_string( $value['data'] ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Lossless transport of database/file bytes, never evaluation or execution of encoded content.
					$value = base64_decode( $value['data'], true );
					if ( $value === false ) {
						return new WP_Error( 'in_synced_bad_rows', __( 'Invalid base64 database value.', 'in-synced' ), array( 'status' => 400 ) );
					}
				} elseif ( $value !== null && ! is_int( $value ) && ! is_float( $value ) ) {
					return new WP_Error( 'in_synced_bad_rows', __( 'Database strings must use the v2 base64 encoding.', 'in-synced' ), array( 'status' => 400 ) );
				}
			}
			unset( $value );
		}
		unset( $row );
		return $rows;
	}

	public static function relative_path( $path ): string {
		if ( ! is_string( $path ) || preg_match( '/[\x00-\x1f\x7f:]/', $path ) ) {
			return '';
		}
		$path = str_replace( '\\', '/', $path );
		if ( $path === '' || $path[0] === '/' || substr( $path, -1 ) === '/' ) {
			return '';
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( $part === '' || $part === '.' || $part === '..' || preg_match( '/[. ]$/D', $part ) || preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part ) ) {
				return '';
			}
		}
		return $path;
	}

	public static function upload_path_allowed( string $path ): bool {
		if ( ! self::relative_path( $path ) ) {
			return false;
		}
		foreach ( explode( '/', strtolower( $path ) ) as $part ) {
			if ( $part[0] === '.' || $part === 'in-synced-backups' || $part === 'web.config' || preg_match( '/\.(?:php\d*|phtml|pht|phar|phps|cgi|pl|py|sh|shtml|shtm|htaccess|ini|asp|aspx|ashx|asmx|config)(?:\.|$)/i', $part ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Reject symlinks beneath the approved root, including the destination.
	 *
	 * @param string $root Approved root.
	 * @param string $relative Relative file path.
	 */
	public static function confined_path( string $root, string $relative ): bool {
		if ( ! self::relative_path( $relative ) || ! is_dir( $root ) ) {
			return false;
		}
		$root = realpath( $root );
		if ( $root === false ) {
			return false;
		}
		$current = $root;
		foreach ( explode( '/', $relative ) as $part ) {
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $current ) ) {
				return false;
			}
			if ( file_exists( $current ) ) {
				$resolved = realpath( $current );
				if ( $resolved === false || strpos( $resolved, $root . DIRECTORY_SEPARATOR ) !== 0 ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Private storage must resolve outside every known served directory.
	 *
	 * @param string $directory Existing storage directory.
	 * @param array  $public_roots Served directory roots.
	 */
	public static function private_directory( string $directory, array $public_roots ): bool {
		$directory = realpath( $directory );
		if ( $directory === false ) {
			return false;
		}
		foreach ( $public_roots as $public_root ) {
			$public_root = realpath( $public_root );
			if ( $public_root && ( $directory === $public_root || strpos( $directory, rtrim( $public_root, '/\\' ) . DIRECTORY_SEPARATOR ) === 0 ) ) {
				return false;
			}
		}
		return true;
	}

	public static function same_filesystem( string $first, string $second ): bool {
		clearstatcache( true, $first );
		clearstatcache( true, $second );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filesystem race or inaccessible path must fail closed without exposing absolute storage paths in PHP warnings.
		$first_stat = @stat( $first );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filesystem race or inaccessible path must fail closed without exposing absolute storage paths in PHP warnings.
		$second_stat = @stat( $second );
		return is_array( $first_stat ) && is_array( $second_stat ) && isset( $first_stat['dev'], $second_stat['dev'] ) && $first_stat['dev'] === $second_stat['dev'];
	}

	/**
	 * Refuse a cross-device copy fallback; it cannot preserve atomic replacement.
	 *
	 * @param string $source Verified private staged file.
	 * @param string $destination Confined approved destination.
	 */
	public static function atomic_replace( string $source, string $destination ): bool {
		if ( ! self::same_filesystem( $source, dirname( $destination ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Caller verifies private source, confined administrator-approved destination, size/hash and same filesystem before native atomic replacement.
		return rename( $source, $destination );
	}

	/**
	 * Retry short writes and abort on zero/error.
	 *
	 * @param resource $handle Writable stream.
	 * @param string   $data Bytes to write.
	 */
	public static function write_all( $handle, string $data ): bool {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native streams, advisory locks and same-filesystem rename are required for verified atomic transfers; approved paths are confined.
			$written = fwrite( $handle, substr( $data, $offset ) );
			if ( $written === false || $written === 0 ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	public static function schema_fingerprint( string $sql ): string {
		$sql    = preg_replace( '/^CREATE TABLE `[^`]+`/i', 'CREATE TABLE', trim( $sql ) );
		$quoted = array();
		$masked = '';
		for ( $index = 0, $length = strlen( $sql ); $index < $length; $index++ ) {
			$char = $sql[ $index ];
			if ( $char !== "'" && $char !== '"' && $char !== '`' ) {
				$masked .= $char;
				continue;
			}
			$quote   = $char;
			$literal = $char;
			while ( ++$index < $length ) {
				$char     = $sql[ $index ];
				$literal .= $char;
				if ( $char === '\\' && $quote !== '`' && isset( $sql[ $index + 1 ] ) ) {
					$literal .= $sql[ ++$index ];
				} elseif ( $char === $quote ) {
					if ( isset( $sql[ $index + 1 ] ) && $sql[ $index + 1 ] === $quote ) {
						$literal .= $sql[ ++$index ];
					} else {
						break;
					}
				}
			}
			$placeholder            = "\x01" . count( $quoted ) . "\x02";
			$quoted[ $placeholder ] = $literal;
			$masked                .= $placeholder;
		}
		$masked = preg_replace( '/\bAUTO_INCREMENT\s*=\s*\d+/i', '', $masked );
		$masked = trim( preg_replace( '/\s+/', ' ', $masked ) );
		return hash( 'sha256', strtr( $masked, $quoted ) );
	}

	/**
	 * Accept a single conservative SHOW CREATE TABLE statement.
	 *
	 * @param string $sql Sender schema.
	 * @param string $source Source table name.
	 * @param string $target Validated target table name.
	 */
	public static function create_table_sql( string $sql, string $source, string $target ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $source ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $target ) ) {
			return '';
		}
		$sql = trim( $sql );
		if ( ! preg_match( '/^CREATE\s+TABLE\s+`' . preg_quote( $source, '/' ) . '`\s*\(/i', $sql, $match ) ) {
			return '';
		}
		$start = strlen( $match[0] ) - 1;
		$depth = 0;
		$quote = '';
		$mask  = '';
		$end   = null;
		for ( $index = $start, $length = strlen( $sql ); $index < $length; $index++ ) {
			$char = $sql[ $index ];
			if ( $quote !== '' ) {
				$mask .= ' ';
				if ( $char === '\\' && $quote !== '`' ) {
					++$index;
					$mask .= ' ';
				} elseif ( $char === $quote ) {
					if ( isset( $sql[ $index + 1 ] ) && $sql[ $index + 1 ] === $quote ) {
						++$index;
						$mask .= ' ';
					} else {
						$quote = '';
					}
				}
				continue;
			}
			if ( $char === "'" || $char === '"' || $char === '`' ) {
				$quote = $char;
				$mask .= ' ';
				continue;
			}
			if ( $char === ';' || $char === '#' || substr( $sql, $index, 2 ) === '--' || substr( $sql, $index, 2 ) === '/*' ) {
				return '';
			}
			$mask .= $char;
			if ( $char === '(' ) {
				++$depth;
			} elseif ( $char === ')' ) {
				--$depth;
				if ( $depth === 0 ) {
					$end = $index;
					break;
				}
			}
		}
		if ( $end === null || $quote !== '' || preg_match( '/\b(?:REFERENCES|FOREIGN|SELECT|LIKE|UNION|INTO|LOAD|OUTFILE|GENERATED|VIRTUAL|STORED|AS)\b/i', $mask ) ) {
			return '';
		}
		$tail = trim( substr( $sql, $end + 1 ) );
		// Unknown storage engines, executable comments, partition expressions, external
		// directories and network connections require manual schema provisioning.
		$option = '(?:ENGINE\s*=\s*(?:InnoDB|MyISAM)|AUTO_INCREMENT\s*=\s*\d+|(?:DEFAULT\s+)?CHARSET\s*=\s*[A-Za-z0-9_]+|(?:DEFAULT\s+)?CHARACTER\s+SET\s*=\s*[A-Za-z0-9_]+|COLLATE\s*=\s*[A-Za-z0-9_]+|ROW_FORMAT\s*=\s*(?:DYNAMIC|COMPACT|REDUNDANT|COMPRESSED|FIXED)|KEY_BLOCK_SIZE\s*=\s*\d+|COMMENT\s*=\s*\'(?:[^\'\\\\]|\\\\.|\'\')*\')';
		if ( $tail !== '' && ! preg_match( '/^(?:' . $option . '\s*)+$/iD', $tail ) ) {
			return '';
		}
		return 'CREATE TABLE `' . $target . '` ' . substr( $sql, $start );
	}
}
