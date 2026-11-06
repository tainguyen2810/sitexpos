<?php
/**
 * PHP 7.4 compatibility check for the plugin (the plugin supports PHP 7.4+).
 * Parses every file with nikic/php-parser targeting PHP 7.4 and flags calls
 * to functions that only exist in PHP 8.
 *
 *   php check-php74.php <plugin-dir> <path-to-vendor/autoload.php>
 */
require $argv[2];

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$php8 = array( 'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'array_is_list', 'fdiv', 'get_resource_id', 'enum_exists', 'array_find', 'mb_str_pad', 'json_validate' );
$parser = ( new ParserFactory() )->createForVersion( PhpVersion::fromComponents( 7, 4 ) );
$finder = new NodeFinder();
$errors = 0;
$files  = 0;
$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $argv[1], FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$files++;
	try {
		$ast = $parser->parse( file_get_contents( $file->getPathname() ) );
	} catch ( PhpParser\Error $e ) {
		echo $file->getPathname() . ': ' . $e->getMessage() . "\n";
		$errors++;
		continue;
	}
	$bad = $finder->find(
		$ast,
		function ( Node $n ) use ( $php8 ) {
			return ( $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && in_array( strtolower( $n->name->toString() ), $php8, true ) )
				|| $n instanceof Node\Expr\Match_
				|| $n instanceof Node\Expr\NullsafeMethodCall
				|| $n instanceof Node\Expr\NullsafePropertyFetch
				|| ( $n instanceof Node\Arg && null !== $n->name )
				|| ( $n instanceof Node\Param && ( 0 !== $n->flags || $n->type instanceof Node\UnionType ) )
				|| $n instanceof Node\Stmt\Enum_
				|| ( $n instanceof Node\Stmt\Property && ( $n->flags & Node\Stmt\Class_::MODIFIER_READONLY ) );
		}
	);
	foreach ( $bad as $n ) {
		echo $file->getPathname() . ':' . $n->getStartLine() . ': PHP 8+ syntax or function (' . $n->getType() . ")\n";
		$errors++;
	}
}
echo "{$files} files checked, {$errors} problems\n";
exit( $errors ? 1 : 0 );
