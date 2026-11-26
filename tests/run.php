<?php
/*
 * regression tests (no dependency)
 * usage: php tests/run.php
 *   IO_Bit and IO_ICC are loaded from vendor/ (composer install) or include_path.
 */

$root = dirname(dirname(__FILE__));
if (is_readable($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
set_include_path($root.PATH_SEPARATOR.get_include_path());
require_once 'IO/ISOBMFF.php';

$failed = 0;
$passed = 0;

function check($name, $expect, $actual) {
    global $failed, $passed;
    if ($expect === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name".PHP_EOL;
    echo "  expect: ".var_export($expect, true).PHP_EOL;
    echo "  actual: ".var_export($actual, true).PHP_EOL;
}

function box($type, $payload) {
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function fullbox($type, $version, $flags, $payload) {
    return box($type, chr($version).substr(pack('N', $flags), 1).$payload);
}

function parseISOBMFF($data, $opts = array()) {
    $isobmff = new IO_ISOBMFF();
    $isobmff->parse($data, $opts);
    return $isobmff;
}

// build() result, or the exception class and message
function rebuild($data) {
    try {
        return parseISOBMFF($data)->build();
    } catch (Throwable $e) {
        return get_class($e).": ".$e->getMessage();
    }
}

/*
 * av1C build (reserved bits had no width)
 */
$av1C = box('av1C', "\x81\x00\x0c\x00"."\x0a\x0b\x00\x00\x00\x24\xc6\xab\xdf\xfe\x00");
check('av1C rebuild', $av1C, rebuild($av1C));

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
