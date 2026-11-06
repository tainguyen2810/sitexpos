<?php
/**
 * Minimal i18n tooling for the SiteX Post plugin (no WP-CLI needed).
 *
 *   php i18n.php pot  <plugin-dir> <out.pot>      extract strings for text domain sitex-post
 *   php i18n.php po   <in.pot> <translations.php> <out.po>   merge a msgid => msgstr map
 *   php i18n.php mo   <in.po> <out.mo>            compile a .po file
 *   php i18n.php check <in.pot> <translations.php> list untranslated / stale strings
 */

const DOMAIN    = 'sitex-post';
const FUNCTIONS = array(
	'__'         => array( 'text' => 0, 'domain' => 1 ),
	'_e'         => array( 'text' => 0, 'domain' => 1 ),
	'esc_html__' => array( 'text' => 0, 'domain' => 1 ),
	'esc_html_e' => array( 'text' => 0, 'domain' => 1 ),
	'esc_attr__' => array( 'text' => 0, 'domain' => 1 ),
	'esc_attr_e' => array( 'text' => 0, 'domain' => 1 ),
	'_x'         => array( 'text' => 0, 'context' => 1, 'domain' => 2 ),
	'_n'         => array( 'text' => 0, 'plural' => 1, 'domain' => 3 ),
);

function extract_strings( $dir ) {
	$entries = array();
	$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	$files   = array();
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );
	foreach ( $files as $path ) {
		$tokens  = token_get_all( file_get_contents( $path ) );
		$rel     = ltrim( substr( $path, strlen( $dir ) ), '/' );
		$comment = null;
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && false !== stripos( $t[1], 'translators:' ) ) {
				$comment = trim( preg_replace( '#^\s*(/\*+|//|\*/|\*)\s?#m', '', $t[1] ) );
				continue;
			}
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( FUNCTIONS[ $t[1] ] ) ) {
				continue;
			}
			// Must be a call: next significant token is "(".
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
				continue;
			}
			$args  = array();
			$depth = 0;
			$cur   = array();
			for ( $k = $j + 1; $k < $count; $k++ ) {
				$tk = $tokens[ $k ];
				if ( '(' === $tk || '[' === $tk ) {
					$depth++;
				} elseif ( ')' === $tk || ']' === $tk ) {
					if ( 0 === $depth ) {
						$args[] = $cur;
						break;
					}
					$depth--;
				} elseif ( ',' === $tk && 0 === $depth ) {
					$args[] = $cur;
					$cur    = array();
					continue;
				}
				if ( is_array( $tk ) && T_WHITESPACE === $tk[0] ) {
					continue;
				}
				$cur[] = $tk;
			}
			$lit = function ( $arg ) {
				if ( 1 !== count( (array) $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
					return null;
				}
				return eval( 'return ' . $arg[0][1] . ';' ); // phpcs:ignore -- literal string token only.
			};
			$spec   = FUNCTIONS[ $t[1] ];
			$domain = isset( $args[ $spec['domain'] ] ) ? $lit( $args[ $spec['domain'] ] ) : null;
			$text   = isset( $args[ $spec['text'] ] ) ? $lit( $args[ $spec['text'] ] ) : null;
			if ( DOMAIN !== $domain || null === $text ) {
				continue;
			}
			$ctx    = isset( $spec['context'] ) ? $lit( $args[ $spec['context'] ] ) : null;
			$plural = isset( $spec['plural'] ) ? $lit( $args[ $spec['plural'] ] ) : null;
			$key    = ( null === $ctx ? '' : $ctx . "\4" ) . $text;
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array( 'msgid' => $text, 'msgctxt' => $ctx, 'plural' => $plural, 'refs' => array(), 'comments' => array() );
			}
			$entries[ $key ]['refs'][] = $rel . ':' . $t[2];
			if ( null !== $comment ) {
				$entries[ $key ]['comments'][] = $comment;
				$comment                       = null;
			}
		}
	}
	return $entries;
}

function po_escape( $s ) {
	return '"' . addcslashes( $s, "\\\"\n\t" ) . '"';
}

function write_po( array $entries, array $translations, array $header ) {
	$out = "msgid \"\"\nmsgstr \"\"\n";
	foreach ( $header as $k => $v ) {
		$out .= po_escape( "{$k}: {$v}\n" ) . "\n";
	}
	foreach ( $entries as $key => $e ) {
		$out .= "\n";
		foreach ( array_unique( $e['comments'] ) as $c ) {
			$out .= '#. ' . str_replace( "\n", "\n#. ", $c ) . "\n";
		}
		$out .= '#: ' . implode( ' ', array_slice( $e['refs'], 0, 6 ) ) . "\n";
		if ( false !== strpos( $e['msgid'], '%' ) ) {
			$out .= "#, php-format\n";
		}
		if ( null !== $e['msgctxt'] ) {
			$out .= 'msgctxt ' . po_escape( $e['msgctxt'] ) . "\n";
		}
		$out .= 'msgid ' . po_escape( $e['msgid'] ) . "\n";
		$tr   = $translations[ $key ] ?? '';
		if ( null !== $e['plural'] ) {
			$out .= 'msgid_plural ' . po_escape( $e['plural'] ) . "\n";
			$out .= 'msgstr[0] ' . po_escape( is_array( $tr ) ? $tr[0] : $tr ) . "\n";
		} else {
			$out .= 'msgstr ' . po_escape( is_array( $tr ) ? $tr[0] : $tr ) . "\n";
		}
	}
	return $out;
}

function read_po( $path ) {
	$entries = array();
	$cur     = array();
	$field   = null;
	$flush   = function () use ( &$cur, &$entries ) {
		if ( isset( $cur['msgid'] ) ) {
			$entries[] = $cur;
		}
		$cur = array();
	};
	foreach ( file( $path ) as $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) (".*")$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] && isset( $cur['msgid'] ) ) {
				$flush();
			}
			$field         = $m[1];
			$cur[ $field ] = stripcslashes( substr( $m[2], 1, -1 ) );
		} elseif ( preg_match( '/^(".*")$/', $line, $m ) && null !== $field ) {
			$cur[ $field ] .= stripcslashes( substr( $m[1], 1, -1 ) );
		}
	}
	$flush();
	return $entries;
}

function write_mo( array $po ) {
	$map = array();
	foreach ( $po as $e ) {
		$id  = ( isset( $e['msgctxt'] ) ? $e['msgctxt'] . "\4" : '' ) . $e['msgid'];
		$str = $e['msgstr'] ?? ( $e['msgstr[0]'] ?? '' );
		if ( isset( $e['msgid_plural'] ) ) {
			$id  .= "\0" . $e['msgid_plural'];
			$strs = array();
			for ( $n = 0; isset( $e[ "msgstr[{$n}]" ] ); $n++ ) {
				$strs[] = $e[ "msgstr[{$n}]" ];
			}
			$str = implode( "\0", $strs );
		}
		if ( '' === $str && '' !== $e['msgid'] ) {
			continue;
		}
		$map[ $id ] = $str;
	}
	ksort( $map, SORT_STRING );
	$n       = count( $map );
	$o_table = 28;
	$t_table = $o_table + 8 * $n;
	$offset  = $t_table + 8 * $n;
	$ids     = '';
	$strs    = '';
	$o_rows  = array();
	$t_rows  = array();
	foreach ( $map as $id => $str ) {
		$o_rows[] = array( strlen( $id ), strlen( $ids ) );
		$ids     .= $id . "\0";
		$t_rows[] = array( strlen( $str ), strlen( $strs ) );
		$strs    .= $str . "\0";
	}
	$out = pack( 'V7', 0x950412de, 0, $n, $o_table, $t_table, 0, $offset + strlen( $ids ) + strlen( $strs ) );
	foreach ( $o_rows as $r ) {
		$out .= pack( 'VV', $r[0], $offset + $r[1] );
	}
	$str_offset = $offset + strlen( $ids );
	foreach ( $t_rows as $r ) {
		$out .= pack( 'VV', $r[0], $str_offset + $r[1] );
	}
	return $out . $ids . $strs;
}

$cmd = $argv[1] ?? '';
if ( 'pot' === $cmd ) {
	$entries = extract_strings( rtrim( realpath( $argv[2] ), '/' ) );
	$header  = array(
		'Project-Id-Version'        => 'SiteX Post 1.0.0',
		'Report-Msgid-Bugs-To'      => 'https://sitexpost.com/support',
		'MIME-Version'              => '1.0',
		'Content-Type'              => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'X-Domain'                  => DOMAIN,
	);
	file_put_contents( $argv[3], write_po( $entries, array(), $header ) );
	echo count( $entries ) . " strings\n";
} elseif ( 'po' === $cmd || 'check' === $cmd ) {
	$pot          = read_po( $argv[2] );
	$translations = require $argv[3];
	$entries      = array();
	foreach ( $pot as $e ) {
		if ( '' === $e['msgid'] ) {
			continue;
		}
		$key             = ( isset( $e['msgctxt'] ) ? $e['msgctxt'] . "\4" : '' ) . $e['msgid'];
		$entries[ $key ] = array( 'msgid' => $e['msgid'], 'msgctxt' => $e['msgctxt'] ?? null, 'plural' => $e['msgid_plural'] ?? null, 'refs' => array(), 'comments' => array() );
	}
	$missing = array_diff( array_keys( $entries ), array_keys( $translations ) );
	$stale   = array_diff( array_keys( $translations ), array_keys( $entries ) );
	if ( 'check' === $cmd ) {
		foreach ( $missing as $m ) {
			echo "MISSING: {$m}\n";
		}
		foreach ( $stale as $s ) {
			echo "STALE: {$s}\n";
		}
		exit( $missing ? 1 : 0 );
	}
	$header = array(
		'Project-Id-Version'        => 'SiteX Post 1.0.0',
		'Language'                  => 'vi',
		'MIME-Version'              => '1.0',
		'Content-Type'              => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'Plural-Forms'              => 'nplurals=1; plural=0;',
		'X-Domain'                  => DOMAIN,
	);
	file_put_contents( $argv[4], write_po( $entries, $translations, $header ) );
	echo ( count( $entries ) - count( $missing ) ) . '/' . count( $entries ) . " translated\n";
} elseif ( 'mo' === $cmd ) {
	file_put_contents( $argv[3], write_mo( read_po( $argv[2] ) ) );
	echo "ok\n";
} else {
	fwrite( STDERR, "usage: php i18n.php pot|po|mo|check ...\n" );
	exit( 1 );
}
