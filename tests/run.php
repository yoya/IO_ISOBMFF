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

function firstBox($isobmff, $type) {
    $boxes = $isobmff->getBoxesByTypes([$type]);
    return count($boxes) ? $boxes[0] : null;
}

function beN($value, $size) {
    $s = '';
    for ($i = $size - 1; $i >= 0; $i--) {
        $s .= chr(($value >> (8 * $i)) & 0xff);
    }
    return $s;
}

// items: [itemID, baseOffset, [[extentOffset, extentLength], ...], constructionMethod]
function ilocBox($version, $offsetSize, $lengthSize, $baseOffsetSize, $items) {
    $p = chr(($offsetSize << 4) | $lengthSize).chr($baseOffsetSize << 4).pack('n', count($items));
    foreach ($items as $item) {
        $p .= pack('n', $item[0]);
        if ($version >= 1) {
            $p .= pack('n', isset($item[3]) ? $item[3] : 0);
        }
        $p .= pack('n', 0).beN($item[1], $baseOffsetSize).pack('n', count($item[2]));
        foreach ($item[2] as $extent) {
            $p .= beN($extent[0], $offsetSize).beN($extent[1], $lengthSize);
        }
    }
    return fullbox('iloc', $version, 0, $p);
}

// ftyp, meta(iloc), mdat. $ilocFunc(offset of the mdat payload) returns the iloc box.
function makeFile($ilocFunc, $mdatPayload) {
    $ftyp = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
    $meta = fullbox('meta', 0, 0, $ilocFunc(0));
    $start = strlen($ftyp) + strlen($meta) + 8;
    return $ftyp.fullbox('meta', 0, 0, $ilocFunc($start)).box('mdat', $mdatPayload);
}

// item data resolved by the parsed iloc (construction_method 0)
function itemBytes($data, $itemID) {
    $iloc = firstBox(parseISOBMFF($data), 'iloc');
    foreach ($iloc['itemArray'] as $item) {
        if ($item['itemID'] === $itemID) {
            $bytes = '';
            foreach ($item['extentArray'] as $extent) {
                $bytes .= substr($data, $item['baseOffset'] + $extent['extentOffset'], $extent['extentLength']);
            }
            return $bytes;
        }
    }
    return null;
}

// build with a 16-byte box inserted before the last box (mdat), so that mdat moves
function moveMdatAndBuild($data) {
    $isobmff = parseISOBMFF($data);
    $pasp = ["type" => "pasp", "hspace" => 1, "vspace" => 1];
    array_splice($isobmff->boxTree, count($isobmff->boxTree) - 1, 0, [$pasp]);
    return $isobmff->build();
}

/*
 * av1C build (reserved bits had no width)
 */
$av1C = box('av1C', "\x81\x00\x0c\x00"."\x0a\x0b\x00\x00\x00\x24\xc6\xab\xdf\xfe\x00");
check('av1C rebuild', $av1C, rebuild($av1C));

/*
 * several items in one mdat: all of them are relocated
 */
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 0, [[1, 0, [[$s, 4]]], [2, 0, [[$s + 4, 4]]]]);
}, "AAAABBBB");
$out = moveMdatAndBuild($data);
check('shared mdat item 1 relocated', 'AAAA', itemBytes($out, 1));
check('shared mdat item 2 relocated', 'BBBB', itemBytes($out, 2));

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
