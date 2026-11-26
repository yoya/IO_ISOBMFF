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

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
