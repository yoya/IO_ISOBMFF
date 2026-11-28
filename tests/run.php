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

// [return value of $func, messages of the warnings raised in it]
function withWarnings($func) {
    $warnings = [];
    set_error_handler(function($errno, $errstr) use (&$warnings) {
        $warnings []= $errstr;
        return true;
    });
    try {
        $ret = $func();
    } finally {
        restore_error_handler();
    }
    return [$ret, $warnings];
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

/*
 * baseOffsetSize 4 with baseOffset 0: the extent offset is relocated
 */
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 4, [[1, 0, [[$s, 4]]]]);
}, "AAAA");
check('baseOffset 0 relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 4, [[1, $s, [[0, 4]]]]);
}, "AAAA");
check('baseOffset relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));

/*
 * 8-byte offset fields are relocated, other sizes are rejected
 */
$data = makeFile(function($s) {
    return ilocBox(0, 0, 4, 8, [[1, $s, [[0, 4]]]]);
}, "AAAA");
check('baseOffsetSize 8 relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));
$data = makeFile(function($s) {
    return ilocBox(0, 8, 4, 0, [[1, 0, [[$s, 4]]]]);
}, "AAAA");
check('offsetSize 8 relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));
$data = makeFile(function($s) {
    return ilocBox(0, 3, 4, 0, [[1, 0, [[$s, 4]]]]);
}, "AAAA");
check('offsetSize 3 rejected', 'Exception: baseOffsetSize:3 not implement yet.', rebuild($data));

/*
 * idat-relative offsets (construction_method 1) are not relocated
 */
$data = makeFile(function($s) {
    return ilocBox(1, 4, 4, 0, [[1, 0, [[$s, 4]], 1]]);
}, "AAAA");
$iloc = firstBox(parseISOBMFF($data), 'iloc');
$offset = $iloc['itemArray'][0]['extentArray'][0]['extentOffset'];
$iloc = firstBox(parseISOBMFF(moveMdatAndBuild($data)), 'iloc');
check('construction_method 1 not relocated', $offset, $iloc['itemArray'][0]['extentArray'][0]['extentOffset']);

/*
 * iref reference with reference_count 0
 */
$data = fullbox('iref', 0, 0, box('thmb', pack('nn', 2, 0)));
list($isobmff, $warnings) = withWarnings(function() use ($data) {
    return parseISOBMFF($data);
});
check('iref count 0 no warning', [], $warnings);
check('iref count 0 itemArray', [], firstBox($isobmff, 'thmb')['itemArray']);
check('iref count 0 rebuild', $data, rebuild($data));

/*
 * "iloc" child of iref build (typo in the item ID)
 */
$data = fullbox('iref', 0, 0, box('iloc', pack('nnnn', 1, 2, 2, 3)));
check('iref iloc rebuild', $data, rebuild($data));

/*
 * ipma version 1: 32-bit item_ID
 */
$data = fullbox('ipma', 1, 0, pack('NN', 1, 0x10007).chr(1).chr(0x81));
$entry = firstBox(parseISOBMFF($data), 'ipma')['entryArray'][0];
check('ipma v1 itemID', 0x10007, $entry['itemID']);
check('ipma v1 association', [['essential' => 1, 'propertyIndex' => 1]], $entry['associationArray']);
check('ipma v1 rebuild', $data, rebuild($data));

/*
 * iinf version 1: 32-bit entry_count
 */
$data = fullbox('iinf', 1, 0, pack('N', 1).fullbox('infe', 2, 0, pack('nn', 1, 0)."hvc1\0"));
$iinf = firstBox(parseISOBMFF($data), 'iinf');
check('iinf v1 count', 1, $iinf['count']);
check('iinf v1 infe', 'hvc1', $iinf['boxList'][0]['itemType']);
check('iinf v1 rebuild', $data, rebuild($data));

/*
 * infe version 3: 32-bit item_ID
 */
$data = fullbox('infe', 3, 0, pack('Nn', 0x10007, 0)."hvc1Image\0");
$infe = firstBox(parseISOBMFF($data), 'infe');
check('infe v3 itemID', 0x10007, $infe['itemID']);
check('infe v3 itemType', 'hvc1', $infe['itemType']);
check('infe v3 rebuild', $data, rebuild($data));

/*
 * iref version 1: 32-bit from_item_ID and to_item_ID
 */
$data = fullbox('iref', 1, 0, box('dimg', pack('NnNN', 0x10001, 2, 0x10002, 3)));
$dimg = firstBox(parseISOBMFF($data), 'dimg');
check('iref v1 fromItemID', 0x10001, $dimg['fromItemID']);
check('iref v1 itemArray', [['itemID' => 0x10002], ['itemID' => 3]], $dimg['itemArray']);
check('iref v1 rebuild', $data, rebuild($data));
$data = fullbox('iref', 0, 0, box('dimg', pack('nnnn', 1, 2, 2, 3)));
check('iref v0 rebuild', $data, rebuild($data));

/*
 * iloc version 2: 32-bit item_count and item_ID
 */
$data = fullbox('iloc', 2, 0, "\x44\x00".pack('NN', 1, 0x10007).pack('nnn', 0, 0, 1).pack('NN', 0x11111111, 4));
$item = firstBox(parseISOBMFF($data), 'iloc')['itemArray'][0];
check('iloc v2 itemID', 0x10007, $item['itemID']);
check('iloc v2 extent', [['extentOffset' => 0x11111111, 'extentIndex' => 0, 'extentLength' => 4]], $item['extentArray']);
check('iloc v2 rebuild', $data, rebuild($data));

/*
 * tkhd: layer and alternate_group are 16 bits
 */
$data = fullbox('tkhd', 0, 3, pack('NNNNN', 1, 2, 1, 0, 100).str_repeat("\0", 8).
                pack('nnnn', 0xffff, 5, 0x0100, 0).str_repeat("\0", 36).pack('NN', 0x10000, 0x10000));
$tkhd = firstBox(parseISOBMFF($data), 'tkhd');
check('tkhd layer', -1, $tkhd['layer']);
check('tkhd alternateGroup', 5, $tkhd['alternateGroup']);
check('tkhd volume', 0x0100, $tkhd['volume']);
check('tkhd width', 0x10000, $tkhd['width']);

/*
 * largesize smaller than the header is rejected (it looped forever)
 */
$data = pack('N', 1).'free'.pack('NN', 0, 0).box('free', '');
try {
    parseISOBMFF($data);
    $result = 'parsed';
} catch (Exception $e) {
    $result = $e->getMessage();
}
check('largesize 0 rejected', 'parseBox: largesize(0) < 16 (fileOffset:16)', $result);

/*
 * item offset and length in the item tree
 */
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 4, [[1, $s, [[4, 4]]], [2, 0, [[$s, 2], [$s + 2, 2]]], [3, 0, []]]);
}, "AAAABBBB");
list($itemTree, $warnings) = withWarnings(function() use ($data) {
    $isobmff = parseISOBMFF($data);
    $isobmff->analyzeItems(null);
    return $isobmff->itemTree;
});
$start = strpos($data, "AAAABBBB");
check('item tree baseOffset + extentOffset', ['reference' => 0, 'offset' => $start + 4, 'length' => 4], $itemTree[1]['iloc']);
check('item tree extents length', ['reference' => 0, 'offset' => $start, 'length' => 4], $itemTree[2]['iloc']);
check('item tree no extent', ['reference' => 0, 'offset' => 0, 'length' => 0], $itemTree[3]['iloc']);
check('item tree no warning', [], $warnings);

/*
 * infe version 0 (no item_type) in the item tree
 */
$data = fullbox('iinf', 0, 0, pack('n', 1).fullbox('infe', 0, 0, pack('nn', 1, 0)."Image\0"));
list($itemTree, $warnings) = withWarnings(function() use ($data) {
    $isobmff = parseISOBMFF($data);
    $isobmff->analyzeItems(null);
    return $isobmff->itemTree;
});
check('item tree infe v0', ['type' => null], $itemTree[1]['infe']);
check('item tree infe v0 no warning', [], $warnings);

/*
 * tree() hexdump of items in idat (construction_method 1)
 */
$infe = function($itemID) {
    return fullbox('infe', 2, 0, pack('nn', $itemID, 0)."Exif\0");
};
$ipco = box('ipco', fullbox('ispe', 0, 0, pack('NN', 1, 1)));
$ipma = fullbox('ipma', 0, 0, pack('N', 2).pack('n', 1)."\x01\x81".pack('n', 2)."\x01\x81");
$data = fullbox('meta', 0, 0, ilocBox(1, 4, 4, 0, [[1, 0, [[0, 4]], 1], [2, 0, [[4, 4]], 1]]).
                fullbox('iinf', 0, 0, pack('n', 2).$infe(1).$infe(2)).
                box('iprp', $ipco.$ipma).box('idat', "AAAABBBB"));
ob_start();
parseISOBMFF($data)->tree();
preg_match_all('/\(idat:[^)]*\) (.*)/', ob_get_clean(), $matches);
check('tree idat hexdump', ['41 41 41 41 ', '42 42 42 42 '], $matches[1]);

/*
 * sample tools: -r (restrict) is accepted
 */
function runSample($script, $args, $data) {
    global $root;
    $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
         ' '.escapeshellarg("$root/sample/$script").' -f - '.$args;
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fwrite($pipes[0], $data);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}
// hvcC whose first reserved nibble is 0 instead of 0xF
$hvcC = box('hvcC', "\x01\x01".pack('N', 0x60000000).str_repeat("\0", 6)."\x5a"."\x00\x00".
            "\xfc\xfd\xf8\xf8\x00\x00\x0f\x00");
$data = box('ftyp', 'heic'.pack('N', 0).'mif1heic').fullbox('meta', 0, 0, box('iprp', box('ipco', $hvcC)));
foreach (['isobmffdump.php', 'isobmffitems.php', 'isobmffprops.php'] as $script) {
    check("$script without -r", 0, runSample($script, '', $data));
    check("$script -r", 1, runSample($script, '-r', $data));
}

/*
 * hvcC NAL array with reserved bit 1 (libheif 1.23 heif-enc output):
 * accepted without restrict, and kept by build
 */
$hvcC = box('hvcC', "\x01\x01".pack('N', 0x60000000).str_repeat("\0", 6)."\x5a"."\xf0\x00".
            "\xfc\xfd\xf8\xf8\x00\x00\x0f\x02".
            "\x60\x00\x01\x00\x02\x40\x01".   // reserved 1, VPS
            "\x21\x00\x01\x00\x02\x42\x01");  // array_completeness 0, reserved 0, SPS
$data = box('ftyp', 'heic'.pack('N', 0).'mif1heic').fullbox('meta', 0, 0, box('iprp', box('ipco', $hvcC)));
$nalArrays = firstBox(parseISOBMFF($data), 'hvcC')['nalArrays'];
check('hvcC NAL reserved kept', [1, false], [$nalArrays[0]['reserved'], isset($nalArrays[1]['reserved'])]);
check('hvcC NAL type after reserved', [32, 33], array_column($nalArrays, 'NALUnitType'));
check('hvcC NAL reserved rebuild', bin2hex($data), bin2hex(rebuild($data)));
try {
    parseISOBMFF($data, ['restrict' => true]);
    $e = null;
} catch (Exception $e) {
}
check('hvcC NAL reserved restrict', true, $e !== null && strpos($e->getMessage(), 'reserved(1)') !== false);
ob_start();
parseISOBMFF($data)->dump();
check('hvcC NAL reserved dump', 1, substr_count(ob_get_clean(), 'reserved:1 NALUnitType:32'));
foreach (['isobmffdump.php', 'isobmffitems.php', 'isobmffprops.php'] as $script) {
    check("$script hvcC NAL reserved without -r", 0, runSample($script, '', $data));
}

/*
 * box with 64-bit largesize: rebuilt in the same form
 */
function largeBox($type, $payload) {
    return pack('N', 1).$type.pack('NN', 0, 16 + strlen($payload)).$payload;
}
$ftyp = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
$iloc = function($s) {
    return ilocBox(0, 4, 4, 0, [[1, 0, [[$s, 4]]]]);
};
$meta = fullbox('meta', 0, 0, $iloc(0));
$start = strlen($ftyp) + strlen($meta) + 16;
$data = $ftyp.fullbox('meta', 0, 0, $iloc($start)).largeBox('mdat', 'AAAA');
check('largesize rebuild', bin2hex($data), bin2hex(rebuild($data)));
check('largesize relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));

/*
 * item with several extents: every extent offset is relocated
 */
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 0, [[1, 0, [[$s, 2], [$s + 2, 2]]]]);
}, "AAAA");
check('multi-extent rebuild', bin2hex($data), bin2hex(rebuild($data)));
check('multi-extent relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 4, [[1, $s, [[0, 2], [2, 2]]]]);
}, "AAAA");
check('multi-extent baseOffset relocated', 'AAAA', itemBytes(moveMdatAndBuild($data), 1));
// extents in two mdats, each moves by its own amount
$ftyp = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
$iloc = function($s1, $s2) {
    return ilocBox(0, 4, 4, 0, [[1, 0, [[$s1, 2], [$s2, 2]]]]);
};
$meta = fullbox('meta', 0, 0, $iloc(0, 0));
$s1 = strlen($ftyp) + strlen($meta) + 8;
$s2 = $s1 + 2 + 8;
$data = $ftyp.fullbox('meta', 0, 0, $iloc($s1, $s2)).box('mdat', 'AB').box('mdat', 'CD');
check('multi-extent two mdats relocated', 'ABCD', itemBytes(moveMdatAndBuild($data), 1));

/*
 * box size 0 (extends to the end of the file)
 */
$data = makeFile(function($s) {
    return ilocBox(0, 4, 4, 0, [[1, 0, [[$s, 4]]]]);
}, "AAAA");
$data = substr_replace($data, pack('N', 0), strlen($data) - 12, 4); // mdat size 0
$isobmff = parseISOBMFF($data);
$mdat = firstBox($isobmff, 'mdat');
check('size 0 mdat length', 12, $mdat['_length']);
check('size 0 rebuild', bin2hex($data), bin2hex(rebuild($data)));
$out = moveMdatAndBuild($data);
check('size 0 relocated', 'AAAA', itemBytes($out, 1));
check('size 0 last box kept', pack('N', 0).'mdat', substr($out, -12, 8));
// no longer the last box: explicit size
$isobmff = parseISOBMFF($data);
$isobmff->boxTree []= ["type" => "pasp", "hspace" => 1, "vspace" => 1];
$out = $isobmff->build();
check('size 0 not last box', pack('N', 12).'mdat', substr($out, -28, 8));
// size 0 inside a container extends to the end of the parent
$data = fullbox('meta', 0, 0, pack('N', 0).'free'.'xx').box('free', 'yy');
$meta = firstBox(parseISOBMFF($data), 'meta');
check('size 0 in container length', 10, $meta['boxList'][0]['_length']);
check('size 0 in container rebuild', bin2hex(substr_replace($data, pack('N', 10), 12, 4)), bin2hex(rebuild($data)));

/*
 * removeBoxByType of an ipco property: ipma is fixed
 */
$infe = function($itemID) {
    return fullbox('infe', 2, 0, pack('nn', $itemID, 0)."Exif\0");
};
$ipco = box('ipco', fullbox('ispe', 0, 0, pack('NN', 1, 1)).
                    fullbox('pixi', 0, 0, "\x01\x08").
                    box('pasp', pack('NN', 1, 1)));
// item 1: ispe(1) pixi(2) pasp(3), item 2: pixi(2) essential
$ipma = fullbox('ipma', 0, 0, pack('N', 2).pack('n', 1)."\x03\x01\x02\x83".pack('n', 2)."\x01\x82");
$data = fullbox('meta', 0, 0, ilocBox(1, 4, 4, 0, [[1, 0, [[0, 4]], 1], [2, 0, [[4, 4]], 1]]).
                fullbox('iinf', 0, 0, pack('n', 2).$infe(1).$infe(2)).
                box('iprp', $ipco.$ipma).box('idat', "AAAABBBB"));
$isobmff = parseISOBMFF($data);
$isobmff->removeBoxByType(["pixi"]);
$entryArray = firstBox($isobmff, 'ipma')['entryArray'];
check('remove property ipma item 1', [['essential' => 0, 'propertyIndex' => 1], ['essential' => 1, 'propertyIndex' => 2]], $entryArray[0]['associationArray']);
check('remove property ipma item 2', [], $entryArray[1]['associationArray']);
check('remove property ipco', ['ispe', 'pasp'], array_column(firstBox($isobmff, 'ipco')['boxList'], 'type'));
$isobmff = parseISOBMFF($isobmff->build());
check('remove property rebuilt ipma', [['essential' => 0, 'propertyIndex' => 1], ['essential' => 1, 'propertyIndex' => 2]], firstBox($isobmff, 'ipma')['entryArray'][0]['associationArray']);
list($tree, $warnings) = withWarnings(function() use ($isobmff) {
    ob_start();
    try {
        $isobmff->tree();
    } catch (Throwable $e) {
        echo get_class($e);
    }
    return ob_get_clean();
});
check('remove property tree no error', [false, []], [strpos($tree, 'Error'), $warnings]);

/*
 * test data in tests/data (made by tests/data/make.php)
 */
$dataDir = "$root/tests/data";

// run php with $args in the repo root (include_path inherited, every error shown on stderr)
// [exit code (null on timeout), stdout, stderr]
function runPHP($args, $stdin = '', $timeout = 30) {
    global $root;
    $cmd = array_merge([PHP_BINARY, '-d', 'include_path='.get_include_path(),
                        '-d', 'error_reporting='.E_ALL, '-d', 'display_errors=stderr'], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $code = null;
    $deadline = microtime(true) + $timeout;
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = $except = null;
        stream_select($read, $write, $except, 0, 100000);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (! $status['running']) {
            $code = $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            break;
        }
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$code, $out, $err];
}

// PHP errors (warning, notice, deprecated, fatal) in the output of runPHP
function phpErrors($text) {
    preg_match_all('/(?:Warning|Notice|Deprecated|Fatal error|Parse error): .* on line \d+/', $text, $m);
    return $m[0];
}

// $func() output, and the messages of the PHP errors raised in it
function outputOf($func) {
    return withWarnings(function() use ($func) {
        ob_start();
        try {
            $func();
        } finally {
            $out = ob_get_clean();
        }
        return $out;
    });
}

// exception class and message of $func(), or 'no exception'
function exceptionOf($func) {
    ob_start(); // parse in restrict mode var_dumps the box
    try {
        $func();
        $result = 'no exception';
    } catch (Exception $e) {
        $result = get_class($e).': '.$e->getMessage();
    }
    ob_end_clean();
    return $result;
}

function boxTypes($boxList) {
    return array_column($boxList, 'type');
}

// item data of every item: [itemID => bytes] (base_offset + extents, construction_method 0 and 1)
function itemData($data) {
    $isobmff = parseISOBMFF($data);
    $idat = firstBox($isobmff, 'idat');
    $items = [];
    foreach (firstBox($isobmff, 'iloc')['itemArray'] as $item) {
        $method = isset($item['constructionMethod']) ? $item['constructionMethod'] : 0;
        $base = ($method === 1) ? $idat['_offset'] + 8 : 0;
        $bytes = '';
        foreach ($item['extentArray'] as $extent) {
            $bytes .= substr($data, $base + $item['baseOffset'] + $extent['extentOffset'], $extent['extentLength']);
        }
        $items[$item['itemID']] = $bytes;
    }
    return $items;
}

// [itemID => [baseOffset, [[extentOffset, extentLength], ...]]]
function ilocItems($iloc) {
    $items = [];
    foreach ($iloc['itemArray'] as $item) {
        $extents = [];
        foreach ($item['extentArray'] as $extent) {
            $extents []= [$extent['extentOffset'], $extent['extentLength']];
        }
        $items[$item['itemID']] = [$item['baseOffset'], $extents];
    }
    return $items;
}

// [itemID => [[essential, propertyIndex], ...]]
function ipmaEntries($ipma) {
    $entries = [];
    foreach ($ipma['entryArray'] as $entry) {
        $entries[$entry['itemID']] = [];
        foreach ($entry['associationArray'] as $assoc) {
            $entries[$entry['itemID']] []= [$assoc['essential'], $assoc['propertyIndex']];
        }
    }
    return $entries;
}

// [type, fromItemID, [itemID, ...]] of the iref children
function irefRefs($iref) {
    $refs = [];
    foreach ($iref['boxList'] as $box) {
        $refs []= [$box['type'], $box['fromItemID'], array_column($box['itemArray'], 'itemID')];
    }
    return $refs;
}

// build with $box inserted at $index of the top-level boxes
function insertAndBuild($data, $index, $box) {
    $isobmff = parseISOBMFF($data);
    array_splice($isobmff->boxTree, $index, 0, [$box]);
    return $isobmff->build();
}
$pasp = ["type" => "pasp", "hspace" => 1, "vspace" => 1]; // 16 bytes

$dataNames = [];
foreach (glob("$dataDir/*") as $path) {
    if (basename($path) !== 'make.php') {
        $dataNames []= basename($path);
    }
}
check('data files', ['idat_grid.heic', 'items.heic', 'movie.mp4', 'tiny.icc', 'truncated.heic', 'wide.heif'], $dataNames);

/*
 * make.php reproduces the test data
 */
$tmpDir = sys_get_temp_dir().'/io_isobmff_make_'.getmypid();
mkdir($tmpDir);
list($code, $out, $err) = runPHP(["$dataDir/make.php", $tmpDir]);
check('make.php exit', [0, []], [$code, phpErrors($err)]);
foreach ($dataNames as $name) {
    $made = is_file("$tmpDir/$name") ? file_get_contents("$tmpDir/$name") : null;
    check("make.php $name", file_get_contents("$dataDir/$name"), $made);
    if (is_file("$tmpDir/$name")) {
        unlink("$tmpDir/$name");
    }
}
rmdir($tmpDir);

/*
 * items.heic: iloc version 0, iinf version 0, iref version 0, dinf, HEIF properties
 */
$data = file_get_contents("$dataDir/items.heic");
$p = strpos($data, 'IMAGEDAT');
list($isobmff, $warnings) = withWarnings(function() use ($data) {
    return parseISOBMFF($data);
});
check('items parse no warning', [], $warnings);
check('items top boxes', ['ftyp', 'meta', 'mdat'], boxTypes($isobmff->boxTree));
$ftyp = firstBox($isobmff, 'ftyp');
check('items ftyp', ['heic', 0, ['mif1', 'heic']], [$ftyp['major'], $ftyp['minor'], $ftyp['alt']]);
$meta = firstBox($isobmff, 'meta');
check('items meta boxes', ['hdlr', 'dinf', 'pitm', 'iloc', 'iinf', 'iref', 'iprp'], boxTypes($meta['boxList']));
$hdlr = firstBox($isobmff, 'hdlr');
check('items hdlr', ['pict', "Test\0"], [$hdlr['componentSubType'], $hdlr['componentName']]);
$dref = firstBox($isobmff, 'dref');
$url = firstBox($isobmff, 'url ');
check('items dref url', [1, 0, 1, ''], [$dref['entryCount'], $url['version'], $url['flags'], $url['location']]);
check('items pitm', 1, firstBox($isobmff, 'pitm')['itemID']);
$iloc = firstBox($isobmff, 'iloc');
check('items iloc sizes', [0, 4, 4, 4], [$iloc['version'], $iloc['offsetSize'], $iloc['lengthSize'], $iloc['baseOffsetSize']]);
check('items iloc items', [1 => [0, [[$p, 8]]], 2 => [$p + 8, [[0, 2], [2, 3]]],
                           3 => [0, [[$p + 13, 5]]], 4 => [0, [[$p + 18, 9]]]], ilocItems($iloc));
check('items item data', [1 => 'IMAGEDAT', 2 => 'THUMB', 3 => 'EXIF1', 4 => 'MIME-TEXT'], itemData($data));
$iinf = firstBox($isobmff, 'iinf');
check('items iinf', [0, 4], [$iinf['version'], $iinf['count']]);
$infes = [];
foreach ($iinf['boxList'] as $infe) {
    $infes []= [$infe['version'], $infe['itemID'], $infe['itemType'], $infe['itemName'], $infe['contentType']];
}
check('items infe', [[2, 1, 'hvc1', 'Image', null], [2, 2, 'hvc1', 'Thumbnail', null],
                     [2, 3, 'Exif', '', null], [2, 4, 'mime', 'XMP', 'application/rdf+xml']], $infes);
check('items iref', [['thmb', 2, [1]], ['cdsc', 3, [1]], ['cdsc', 4, [1]]], irefRefs(firstBox($isobmff, 'iref')));
$ipco = firstBox($isobmff, 'ipco');
check('items ipco', ['hvcC', 'ispe', 'ispe', 'colr', 'pixi', 'irot', 'pasp', 'clap'], boxTypes($ipco['boxList']));
$hvcC = firstBox($isobmff, 'hvcC');
check('items hvcC', [1, 0, 0, 1, 0x60000000, 0x900000000000, 60, 0, 0, 1, 0, 0, 1, 1, 3, 3],
      [$hvcC['version'], $hvcC['profileSpace'], $hvcC['tierFlag'], $hvcC['profileIdc'],
       $hvcC['profileCompatibilityFlags'], $hvcC['constraintIndicatorFlags'], $hvcC['levelIdc'],
       $hvcC['minSpatialSegmentationIdc'], $hvcC['parallelismType'], $hvcC['chromaFormat'],
       $hvcC['bitDepthLumaMinus8'], $hvcC['bitDepthChromaMinus8'], $hvcC['numTemporalLayers'],
       $hvcC['temporalIdNested'], $hvcC['lengthSizeMinusOne'], $hvcC['numOfArrays']]);
$nals = [];
foreach ($hvcC['nalArrays'] as $nal) {
    $nals []= [$nal['array_completeness'], $nal['NALUnitType'], array_column($nal['nalus'], 'nalUnit')];
}
check('items hvcC NAL arrays', [[1, 32, ["\x40\x01VPS"]], [1, 33, ["\x42\x01SPS"]], [1, 34, ["\x44\x01PPS"]]], $nals);
$props = $ipco['boxList'];
check('items ispe', [[64, 48], [16, 12]], [[$props[1]['width'], $props[1]['height']], [$props[2]['width'], $props[2]['height']]]);
check('items colr nclx', ['nclx', pack('nnn', 1, 13, 6)."\x80"], [$props[3]['subtype'], $props[3]['data']]);
check('items pixi', [3, [8, 8, 8]], [$props[4]['channelCount'], array_column($props[4]['channelArray'], 'bitsPerChannel')]);
check('items irot pasp', [1, 1, 1], [$props[5]['angle'], $props[6]['hspace'], $props[6]['vspace']]);
check('items clap', [60, 1, 40, 1, -2, 1, 0, 1],
      [$props[7]['width_N'], $props[7]['width_D'], $props[7]['height_N'], $props[7]['height_D'],
       $props[7]['horizOff_N'], $props[7]['horizOff_D'], $props[7]['vertOff_N'], $props[7]['vertOff_D']]);
$ipma = firstBox($isobmff, 'ipma');
check('items ipma', [0, 0, [1 => [[1, 1], [0, 2], [0, 4], [0, 5], [1, 6], [0, 7], [1, 8]], 2 => [[1, 1], [0, 3]]]],
      [$ipma['version'], $ipma['flags'], ipmaEntries($ipma)]);
check('items mdat', [$p - 8, 8 + 27], [firstBox($isobmff, 'mdat')['_offset'], firstBox($isobmff, 'mdat')['_length']]);
// item and property lookup
check('items item boxes 1', ['pitm', 'infe'], boxTypes($isobmff->getItemBoxesByItemID(1)));
check('items item boxes 2', ['infe', 'thmb'], boxTypes($isobmff->getItemBoxesByItemID(2)));
check('items item boxes all', 8, count($isobmff->getItemBoxesByItemID(null)));
check('items item boxes none', null, $isobmff->getItemBoxesByItemID(99));
$propBoxes = $isobmff->getPropBoxesByItemID(2);
check('items props of item 2', ['hvcC', 'ispe', 16], [$propBoxes[0]['type'], $propBoxes[1]['type'], $propBoxes[1]['width']]);
check('items prop index 4', 'colr', $isobmff->getPropBoxesByPropIndex(4)[0]['type']);
check('items prop index all', 9, count($isobmff->getPropBoxesByPropIndex(null)));
// analyze
$isobmff->analyze();
check('items propTree', [['ispe' => ['width' => 64, 'height' => 48]], ['pixi' => ['channels' => [8, 8, 8]]],
                         ['irot' => ['angle' => 1]], ['colr' => ['subtype' => 'nclx']]],
      [$isobmff->propTree[2], $isobmff->propTree[5], $isobmff->propTree[6], $isobmff->propTree[4]]);
check('items propTree hvcC', [1, 60, 1, 8, 8],
      array_values(array_intersect_key($isobmff->propTree[1]['hvcC'],
                                       array_flip(['profile', 'level', 'chroma', 'bitdepthLuma', 'bitdepthChroma']))));
$itemTree = $isobmff->itemTree;
check('items itemTree roles', [[], ['from' => 1], ['from' => 1], ['from' => 1]],
      [$itemTree[1]['pitm'], $itemTree[2]['thmb'], $itemTree[3]['cdsc'], $itemTree[4]['cdsc']]);
check('items itemTree types', ['hvc1', 'hvc1', 'Exif', 'mime'], array_column(array_column($itemTree, 'infe'), 'type'));
check('items itemTree iloc', [['reference' => 0, 'offset' => $p + 8, 'length' => 5],
                              [['essential' => 1, 'index' => 1], ['essential' => 0, 'index' => 3]]],
      [$itemTree[2]['iloc'], $itemTree[2]['ipma']]);
// dump
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump();
});
check('items dump no warning', [], $warnings);
foreach (["type:iloc(offset:".(strpos($data, 'iloc') - 4)." len:", "major:heic minor:0  alt:mif1, heic",
          "itemID:2 dataReferenceIndex:0 baseOffset:".($p + 8), "extentOffset:2 extentLength:3",
          "itemName:XMP contentType:application/rdf+xml", "fromItemID:4", "width:64 height:48",
          "chromaFormat:1(YUV420)", "nalUnitLength:5 nalUnit: 42 01 53 50 53", "angle:1", "horizOff_N:-2",
          "essential:1 propertyIndex:8", "version:0 flags:1  location:"] as $line) {
    check("items dump $line", true, strpos($out, $line) !== false);
}
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump(['typeonly' => true]);
});
$head = "type:ftyp\ntype:meta version:0 flags:0\n    type:hdlr version:0 flags:0\n    type:dinf\n        type:dref version:0 flags:0\n";
check('items dump typeonly', [[], $head], [$warnings, substr($out, 0, strlen($head))]);
check('items dump typeonly lines', 29, substr_count($out, "\n"));
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump(['hexdump' => true]);
});
check('items dump hexdump', [[], true], [$warnings, strlen($out) > strlen($data) * 3]);
// tree
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->tree();
});
check('items tree no warning', [], $warnings);
foreach (["[1]: hvcC profile:1(Main profile) level:60 chroma:1(YUV420)\n", "[6]: irot angle:1\n",
          "[1]: pitm type:hvc1 ref:0 offset:$p length:8\n",
          sprintf("    (mdat:0x%x,0x8) 49 4D 41 47 45 44 41 54 \n", $p),
          "    [1]hvcC:1,60,1 [2?]ispe:64,48 [4?]colr:nclx [5?]pixi:8,8,8 [6]irot:1 [7?]pasp [8]clap \n",
          "[2]: thmb:1 type:hvc1 ref:0 offset:".($p + 8)." length:5\n", "[4]: cdsc:1 type:mime"] as $line) {
    check("items tree $line", true, strpos($out, $line) !== false);
}
check('items tree pitm found', false, strpos($out, 'pitm not found'));
// build
check('items rebuild', bin2hex($data), bin2hex(rebuild($data)));
check('items moved mdat', itemData($data), itemData(moveMdatAndBuild($data)));
check('items moved mdat offset', $p + 16, ilocItems(firstBox(parseISOBMFF(moveMdatAndBuild($data)), 'iloc'))[1][1][0][0]);
// removeBoxByType: iref (meta shrinks, the item offsets follow the mdat)
$isobmff = parseISOBMFF($data);
$isobmff->removeBoxByType(['iref']);
$out = $isobmff->build();
check('items remove iref', [['hdlr', 'dinf', 'pitm', 'iloc', 'iinf', 'iprp'], itemData($data), strlen($data) - 54],
      [boxTypes(firstBox(parseISOBMFF($out), 'meta')['boxList']), itemData($out), strlen($out)]);
// removeBoxByType: properties irot and clap
$isobmff = parseISOBMFF($data);
$isobmff->removeBoxByType(['irot', 'clap']);
$out = parseISOBMFF($isobmff->build());
check('items remove props ipco', ['hvcC', 'ispe', 'ispe', 'colr', 'pixi', 'pasp'], boxTypes(firstBox($out, 'ipco')['boxList']));
check('items remove props ipma', [1 => [[1, 1], [0, 2], [0, 4], [0, 5], [0, 6]], 2 => [[1, 1], [0, 3]]],
      ipmaEntries(firstBox($out, 'ipma')));
// appendICCProfile: colr (prof) at the end of ipco, associated with every item
$icc = file_get_contents("$dataDir/tiny.icc");
$isobmff = parseISOBMFF($data);
$isobmff->appendICCProfile($icc);
$out = $isobmff->build();
$isobmff = parseISOBMFF($out);
$colr = firstBox($isobmff, 'ipco')['boxList'][8];
check('items add icc colr', ['colr', 'prof', $icc], [$colr['type'], $colr['subtype'], $colr['data']]);
check('items add icc ipma', [1 => [[1, 1], [0, 2], [0, 4], [0, 5], [1, 6], [0, 7], [1, 8], [1, 9]], 2 => [[1, 1], [0, 3], [1, 9]]],
      ipmaEntries(firstBox($isobmff, 'ipma')));
check('items add icc item data', itemData($data), itemData($out));
list($tree, $warnings) = outputOf(function() use ($isobmff) {
    $isobmff->tree();
});
check('items add icc tree', [[], true], [$warnings, strpos($tree, "[9]: colr subtype:prof prof:Tiny Test\n") !== false]);
check('items add icc rebuild', bin2hex($out), bin2hex(rebuild($out)));

/*
 * idat_grid.heic: iloc version 1, idat, grid, dimg, auxl, auxC, ipma flags 1
 */
$data = file_get_contents("$dataDir/idat_grid.heic");
$p = strpos($data, 'TIL1');
list($isobmff, $warnings) = withWarnings(function() use ($data) {
    return parseISOBMFF($data);
});
check('grid parse no warning', [], $warnings);
check('grid meta boxes', ['hdlr', 'pitm', 'iloc', 'iinf', 'iref', 'iprp', 'idat'], boxTypes(firstBox($isobmff, 'meta')['boxList']));
check('grid hdlr name', "\0", firstBox($isobmff, 'hdlr')['componentName']);
$iloc = firstBox($isobmff, 'iloc');
check('grid iloc sizes', [1, 4, 2, 0, 4], [$iloc['version'], $iloc['offsetSize'], $iloc['lengthSize'], $iloc['baseOffsetSize'], $iloc['indexSize']]);
check('grid iloc methods', [1, 0, 0, 0, 0, 0], array_column($iloc['itemArray'], 'constructionMethod'));
$indexes = [];
foreach ($iloc['itemArray'] as $item) {
    $indexes []= $item['extentArray'][0]['extentIndex'];
}
check('grid iloc extent index', [0, 1, 2, 3, 4, 0], $indexes);
check('grid iloc items', [1 => [0, [[0, 8]]], 2 => [0, [[$p, 4]]], 3 => [0, [[$p + 4, 4]]], 4 => [0, [[$p + 8, 4]]],
                          5 => [0, [[$p + 12, 4]]], 6 => [0, [[$p + 16, 4]]]], ilocItems($iloc));
$grid = "\x00\x00\x01\x01".pack('nn', 32, 24);
check('grid item data', [1 => $grid, 2 => 'TIL1', 3 => 'TIL2', 4 => 'TIL3', 5 => 'TIL4', 6 => 'ALPH'], itemData($data));
check('grid iinf types', ['grid', 'hvc1', 'hvc1', 'hvc1', 'hvc1', 'hvc1'], array_column(firstBox($isobmff, 'iinf')['boxList'], 'itemType'));
check('grid iref', [['dimg', 1, [2, 3, 4, 5]], ['auxl', 6, [1]]], irefRefs(firstBox($isobmff, 'iref')));
$auxC = firstBox($isobmff, 'auxC');
check('grid auxC', [0, 0, 'urn:mpeg:hevc:2015:auxid:1', "\x01\x02"], [$auxC['version'], $auxC['flags'], $auxC['auxType'], $auxC['auxSubType']]);
$ipma = firstBox($isobmff, 'ipma');
check('grid ipma', [0, 1, [1 => [[0, 2]], 2 => [[1, 1], [0, 3]], 3 => [[1, 1], [0, 3]], 4 => [[1, 1], [0, 3]],
                           5 => [[1, 1], [0, 3]], 6 => [[1, 1], [0, 3], [1, 4]]]],
      [$ipma['version'], $ipma['flags'], ipmaEntries($ipma)]);
$isobmff->analyze();
check('grid itemTree', [['type' => 'grid'], ['from' => 1], ['from' => 1], ['reference' => 0, 'offset' => 0, 'length' => 8, 'method' => 1]],
      [$isobmff->itemTree[1]['infe'], $isobmff->itemTree[2]['dimg'], $isobmff->itemTree[6]['auxl'], $isobmff->itemTree[1]['iloc']]);
check('grid propTree auxC', ['auxType' => 'urn:mpeg:hevc:2015:auxid:1', 'auxSubType' => "\x01\x02"], $isobmff->propTree[4]['auxC']);
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump();
});
check('grid dump no warning', [], $warnings);
foreach (["version:1 flags:0  offsetSize:4 lengthSize:2 baseOffsetSize:0 indexSize:4",
          "itemID:1 constructionMethod:1 dataReferenceIndex:0 baseOffset:0", "extentOffset:$p extentIndex:1 extentLength:4",
          "auxType:urn:mpeg:hevc:2015:auxid:1", "version:0 flags:1\n", "type:idat("] as $line) {
    check("grid dump $line", true, strpos($out, $line) !== false);
}
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->tree();
});
check('grid tree no warning', [], $warnings);
foreach (["[4]: auxC auxtype:urn:mpeg:hevc:2015:auxid:1\n", "[1]: pitm type:grid method:1 ref:0 offset:0 length:8\n",
          "    (idat) version:0 flags:0 rows-1:1 cols-1:1 width:32 height:24", "[3]: dimg:1 type:hvc1 method:0",
          "[6]: auxl:1 type:hvc1", "    [1]hvcC:1,60,1 [3?]ispe:16,12 [4]auxC \n"] as $line) {
    check("grid tree $line", true, strpos($out, $line) !== false);
}
check('grid rebuild', bin2hex($data), bin2hex(rebuild($data)));
$out = moveMdatAndBuild($data);
check('grid moved mdat', itemData($data), itemData($out));
check('grid moved mdat idat offset kept', [0, [[0, 8]]], ilocItems(firstBox(parseISOBMFF($out), 'iloc'))[1]);
$isobmff = parseISOBMFF($data);
$isobmff->removeBoxByType(['auxC']);
check('grid remove auxC ipma 6', [[1, 1], [0, 3]], ipmaEntries(firstBox($isobmff, 'ipma'))[6]);

/*
 * wide.heif: 32-bit IDs and counts (iloc v2, iinf v1, infe v3, iref v1, ipma v1),
 * 8-byte iloc fields, av1C, unknown boxes, largesize mdat, size 0 last box
 */
$data = file_get_contents("$dataDir/wide.heif");
$p = strpos($data, 'AV01');
list($isobmff, $warnings) = withWarnings(function() use ($data) {
    return parseISOBMFF($data);
});
check('wide parse no warning', [], $warnings);
check('wide top boxes', ['ftyp', 'meta', 'uuid', 'mdat', 'free'], boxTypes($isobmff->boxTree));
$iloc = firstBox($isobmff, 'iloc');
check('wide iloc sizes', [2, 8, 8, 8, 0, 2], [$iloc['version'], $iloc['offsetSize'], $iloc['lengthSize'], $iloc['baseOffsetSize'], $iloc['indexSize'], $iloc['itemCount']]);
check('wide iloc items', [2 => [$p, [[0, 4]]], 0x10001 => [0, [[$p + 4, 3], [$p + 7, 3]]]], ilocItems($iloc));
check('wide item data', [2 => 'AV01', 0x10001 => 'EXIFDA'], itemData($data));
$iinf = firstBox($isobmff, 'iinf');
check('wide iinf', [1, 2, [2, 3], [2, 0x10001], ['av01', 'Exif'], ['Image', 'ExifData']],
      [$iinf['version'], $iinf['count'], array_column($iinf['boxList'], 'version'), array_column($iinf['boxList'], 'itemID'),
       array_column($iinf['boxList'], 'itemType'), array_column($iinf['boxList'], 'itemName')]);
$iref = firstBox($isobmff, 'iref');
check('wide iref', [1, [['cdsc', 0x10001, [2]]]], [$iref['version'], irefRefs($iref)]);
check('wide ipco', ['av1C', 'ispe', 'pixi', 'zzzz'], boxTypes(firstBox($isobmff, 'ipco')['boxList']));
$ipma = firstBox($isobmff, 'ipma');
check('wide ipma', [1, 1, [2 => [[1, 1], [0, 2], [0, 3], [0, 4]]]], [$ipma['version'], $ipma['flags'], ipmaEntries($ipma)]);
$av1C = firstBox($isobmff, 'av1C');
check('wide av1C', [1, 1, 0, 8, 0, 1, 0, 0, 1, 1, 0, 1, 5, "\x0a\x0b\x00\x00\x00\x24\xc4\xff\xdf\x00\x68\x02"],
      [$av1C['marker'], $av1C['version'], $av1C['seq_profile'], $av1C['seq_level_idx_0'], $av1C['seq_tier_0'],
       $av1C['high_bitdepth'], $av1C['twelve_bit'], $av1C['monochrome'], $av1C['chroma_subsampling_x'],
       $av1C['chroma_subsampling_y'], $av1C['chroma_sample_position'], $av1C['initial_presentation_delay_present'],
       $av1C['initial_presentation_delay_minus_one'], $av1C['configOBUs']]);
check('wide pixi', [10, 10, 10], array_column(firstBox($isobmff, 'pixi')['channelArray'], 'bitsPerChannel'));
$mdat = firstBox($isobmff, 'mdat');
check('wide mdat largesize', [true, 16 + 10, $p - 16], [$mdat['_largesize'], $mdat['_length'], $mdat['_offset']]);
$free = firstBox($isobmff, 'free');
check('wide free size 0', [true, 12, strlen($data) - 12], [$free['_sizeZero'], $free['_length'], $free['_offset']]);
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump();
});
check('wide dump no warning', [], $warnings);
foreach (["version:2 flags:0  offsetSize:8 lengthSize:8 baseOffsetSize:8 indexSize:0", "itemID:65537 constructionMethod:0",
          "version:3 flags:0  itemID:65537", "fromItemID:65537", "marker:1 version:1 seq_profile:0 seq_level_idx_0:8\n",
          "seq_tier_0:0 high_bitdepth:1 twelve_bit:0 monochrome:0\n", "initial_presentation_delay_minus_one:5\n",
          "configOBUs: 0a 0b 00 00 00 24 c4 ff df 00 68 02\n", "type:zzzz(", "type:uuid(", "version:1 flags:1\n"] as $line) {
    check("wide dump $line", true, strpos($out, $line) !== false);
}
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->tree();
});
check('wide tree', [[], true, true], [$warnings, strpos($out, "[2]: pitm type:av01 method:0 ref:0 offset:$p length:4\n") !== false,
                                     strpos($out, "[65537]: cdsc:2 type:Exif method:0 ref:0 offset:".($p + 4)." length:6\n") !== false]);
check('wide rebuild', bin2hex($data), bin2hex(rebuild($data)));
$out = insertAndBuild($data, 2, $pasp);  // before uuid and mdat
check('wide moved mdat', itemData($data), itemData($out));
check('wide moved mdat offsets', [2 => [$p + 16, [[0, 4]]], 0x10001 => [0, [[$p + 20, 3], [$p + 23, 3]]]],
      ilocItems(firstBox(parseISOBMFF($out), 'iloc')));
check('wide moved last box size 0', pack('N', 0).'free'.'tail', substr($out, -12));

/*
 * movie.mp4: moov, trak, mdia, mvhd version 1, tkhd version 1, unparsed boxes
 */
$data = file_get_contents("$dataDir/movie.mp4");
list($isobmff, $warnings) = withWarnings(function() use ($data) {
    return parseISOBMFF($data);
});
check('movie parse no warning', [], $warnings);
check('movie boxes', [['ftyp', 'moov', 'mdat'], ['mvhd', 'trak', 'udta'], ['tkhd', 'mdia'], ['mdhd', 'hdlr', 'minf']],
      [boxTypes($isobmff->boxTree), boxTypes(firstBox($isobmff, 'moov')['boxList']),
       boxTypes(firstBox($isobmff, 'trak')['boxList']), boxTypes(firstBox($isobmff, 'mdia')['boxList'])]);
$ftyp = firstBox($isobmff, 'ftyp');
check('movie ftyp', ['isom', 0x200, ['isom', 'mp41']], [$ftyp['major'], $ftyp['minor'], $ftyp['alt']]);
$mvhd = firstBox($isobmff, 'mvhd');
check('movie mvhd', [1, 0x100000000, 0x100000001, 600, 0x123456789, 0x10000, 0x100, 2],
      [$mvhd['version'], $mvhd['creationTime'], $mvhd['modificationTime'], $mvhd['timeScale'], $mvhd['duration'],
       $mvhd['preferredRate'], $mvhd['preferredVolume'], $mvhd['nextTrackID']]);
check('movie mvhd matrix', [0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000], $mvhd['matrix']);
$tkhd = firstBox($isobmff, 'tkhd');
check('movie tkhd', [1, 7, 0x100000000, 1, 0x123456789, -1, 2, 0, 320 << 16, 240 << 16],
      [$tkhd['version'], $tkhd['flags'], $tkhd['creationTime'], $tkhd['trackId'], $tkhd['duration'],
       $tkhd['layer'], $tkhd['alternateGroup'], $tkhd['volume'], $tkhd['width'], $tkhd['height']]);
$hdlr = firstBox($isobmff, 'hdlr');
check('movie hdlr', ['vide', "VideoHandler\0"], [$hdlr['componentSubType'], $hdlr['componentName']]);
list($out, $warnings) = outputOf(function() use ($data) {
    parseISOBMFF($data)->dump();
});
check('movie dump', [[], true, true, true], [$warnings, strpos($out, "duration:4886718345\n") !== false,
                                            strpos($out, "layer:-1\n") !== false, strpos($out, "type:minf(") !== false]);
check('movie rebuild', bin2hex($data), bin2hex(rebuild($data)));

/*
 * broken input
 */
$data = file_get_contents("$dataDir/truncated.heic");
check('truncated parse', 'Exception: parseBox: hasNext(boxLength:632 - 8) === false (boxOffset:24) (fileOffset:32)',
      exceptionOf(function() use ($data) {
          parseISOBMFF($data);
      }));
$cases = [
    'boxLength < 8' => [pack('N', 4).'free', [], 'Exception: parseBox: boxLength(4) < 8 (fileOffset:4)'],
    'hdlr no NUL' => [fullbox('hdlr', 0, 0, pack('N', 0).'pict'.str_repeat("\0", 12).'name'), [],
                      'Exception: hdlr componentName need 0 termination'],
    'mvhd version 2' => [fullbox('mvhd', 2, 0, str_repeat("\0", 120)), [], 'Exception: mvhd box version:2 != 0,1'],
    'tkhd version 2' => [fullbox('tkhd', 2, 0, str_repeat("\0", 100)), [], 'Exception: tkhd box version:2 != 0,1'],
    'dref count' => [fullbox('dref', 0, 0, pack('N', 2).fullbox('url ', 0, 1, '')), [],
                     'Exception: parseBox: box[boxList]:'],
    'ipma count' => [fullbox('ipma', 0, 0, pack('N', 3).pack('n', 1)."\x01\x81"), [],
                     'IO_Bit_Exception: getUI16BE: '],
    'iloc count' => [fullbox('iloc', 0, 0, "\x44\x00".pack('n', 2).pack('nnn', 1, 0, 1).pack('NN', 0, 1)), [],
                     'IO_Bit_Exception: '],
    'ispe extra bytes restrict' => [fullbox('ispe', 0, 0, pack('NN', 1, 1).'xx'), ['restrict' => true],
                                    'Exception: type:ispe(boxLen:22) currOffset:20 != (box)nextOffset:22'],
];
foreach ($cases as $name => $case) {
    list($data, $opts, $expect) = $case;
    $result = exceptionOf(function() use ($data, $opts) {
        parseISOBMFF($data, $opts);
    });
    check("broken $name", $expect, substr($result, 0, strlen($expect)));
}
// truncated at every length and broken counts, in a child process with a timeout
$code = <<<'CODE'
require_once 'IO/ISOBMFF.php';
$results = [];
foreach (array_slice($argv, 1) as $path) {
    $data = file_get_contents($path);
    for ($n = 0; $n < strlen($data); $n++) {
        $isobmff = new IO_ISOBMFF();
        try {
            $isobmff->parse(substr($data, 0, $n));
            $results[basename($path)][$n] = count($isobmff->boxTree);
        } catch (Exception $e) {
            $results[basename($path)][$n] = 'exception';
        }
    }
}
echo json_encode($results);
CODE;
$names = ['items.heic', 'idat_grid.heic', 'movie.mp4'];
list($status, $out, $err) = runPHP(array_merge(['-r', $code], array_map(function($name) use ($dataDir) {
    return "$dataDir/$name";
}, $names)), '', 120);
check('truncated sweep exit', [0, []], [$status, phpErrors($err)]);
$results = json_decode($out, true);
foreach ($names as $name) {
    $data = file_get_contents("$dataDir/$name");
    // top-level box boundaries
    $ends = [0];
    for ($offset = 0; $offset < strlen($data); $offset += unpack('N', substr($data, $offset, 4))[1]) {
        $ends []= $offset + unpack('N', substr($data, $offset, 4))[1];
    }
    $expect = [];
    $actual = [];
    for ($n = 0; $n < strlen($data); $n++) {
        $boxes = 0;
        while ($ends[$boxes + 1] <= $n) {
            $boxes++;
        }
        if ($n === $ends[$boxes]) {
            $expect[$n] = $boxes;          // cut at a box boundary: the boxes before it
        } else if ($n - $ends[$boxes] >= 8) {
            $expect[$n] = 'exception';     // cut inside a box
        } else {
            continue;                      // less than a box header left (not checked)
        }
        $actual[$n] = isset($results[$name][$n]) ? $results[$name][$n] : null;
    }
    check("truncated sweep $name", $expect, $actual);
}

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
