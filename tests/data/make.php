<?php
/*
 * generator of the synthetic test data (no dependency)
 * usage: php tests/data/make.php
 *   writes the files next to this script (or into the directory given as the argument).
 *   The output is the same on every run.
 * ref) ISO/IEC 14496-12 (ISOBMFF), ISO/IEC 23008-12 (HEIF), ISO/IEC 14496-15 (hvcC),
 *      AV1 Codec ISO Media File Format Binding (av1C), ICC.1 (ICC profile)
 * The image payloads are dummy bytes.
 */

$dir = isset($argv[1]) ? $argv[1] : dirname(__FILE__);

function box($type, $payload) {
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function fullbox($type, $version, $flags, $payload) {
    return box($type, chr($version).substr(pack('N', $flags), 1).$payload);
}

// box with the 64-bit largesize
function largebox($type, $payload) {
    return pack('N', 1).$type.pack('J', 16 + strlen($payload)).$payload;
}

function beN($value, $size) {
    $s = '';
    for ($i = $size - 1; $i >= 0; $i--) {
        $s .= chr(($value >> (8 * $i)) & 0xff);
    }
    return $s;
}

// $sizes: [offset_size, length_size, base_offset_size, index_size]
// $items: [[item_ID, construction_method, data_reference_index, base_offset,
//           [[extent_offset, extent_index, extent_length], ...]], ...]
function ilocBox($version, $sizes, $items) {
    list($offsetSize, $lengthSize, $baseOffsetSize, $indexSize) = $sizes;
    $idSize = ($version < 2) ? 2 : 4;
    $p = chr(($offsetSize << 4) | $lengthSize).
       chr(($baseOffsetSize << 4) | ($version ? $indexSize : 0)).beN(count($items), $idSize);
    foreach ($items as $item) {
        list($itemID, $method, $dataRef, $baseOffset, $extents) = $item;
        $p .= beN($itemID, $idSize);
        if ($version >= 1) {
            $p .= pack('n', $method);
        }
        $p .= pack('n', $dataRef).beN($baseOffset, $baseOffsetSize).pack('n', count($extents));
        foreach ($extents as $extent) {
            $p .= beN($extent[0], $offsetSize);
            if ($version >= 1) {
                $p .= beN($extent[1], $indexSize);
            }
            $p .= beN($extent[2], $lengthSize);
        }
    }
    return fullbox('iloc', $version, 0, $p);
}

// infe version 2 (16-bit item_ID) or 3 (32-bit item_ID)
function infeBox($version, $itemID, $itemType, $name, $contentType = null) {
    $p = beN($itemID, ($version < 3) ? 2 : 4).pack('n', 0).$itemType.$name."\0";
    if (! is_null($contentType)) {
        $p .= $contentType."\0";
    }
    return fullbox('infe', $version, 0, $p);
}

function iinfBox($version, $infes) {
    return fullbox('iinf', $version, 0, beN(count($infes), $version ? 4 : 2).implode('', $infes));
}

// reference box in iref: $idSize 2 (iref version 0) or 4 (version 1)
function refBox($type, $idSize, $from, $tos) {
    $p = beN($from, $idSize).pack('n', count($tos));
    foreach ($tos as $to) {
        $p .= beN($to, $idSize);
    }
    return box($type, $p);
}

// $entries: [item_ID => [[essential, property_index], ...]]
function ipmaBox($version, $flags, $entries) {
    $p = pack('N', count($entries));
    foreach ($entries as $itemID => $assocs) {
        $p .= beN($itemID, $version ? 4 : 2).chr(count($assocs));
        foreach ($assocs as $assoc) {
            if ($flags & 1) {
                $p .= pack('n', ($assoc[0] << 15) | $assoc[1]);
            } else {
                $p .= chr(($assoc[0] << 7) | $assoc[1]);
            }
        }
    }
    return fullbox('ipma', $version, $flags, $p);
}

function hdlrBox($handler, $name) {
    return fullbox('hdlr', 0, 0, pack('N', 0).$handler.str_repeat("\0", 12).$name."\0");
}

// HEVC Main profile, compatibility 0x60000000, constraint 0x900000000000
// $arrays: [[NAL_unit_type, [nalUnit, ...]], ...] (array_completeness 1)
function hvcCBox($arrays, $level, $chroma, $bitDepthMinus8) {
    $p = "\x01"."\x01".pack('N', 0x60000000).pack('nN', 0x9000, 0).chr($level).
       pack('n', 0xf000).            // reserved 1111, min_spatial_segmentation_idc 0
       "\xfc".                       // reserved 111111, parallelismType 0
       chr(0xfc | $chroma).
       chr(0xf8 | $bitDepthMinus8).chr(0xf8 | $bitDepthMinus8).
       pack('n', 0).                 // avgFrameRate
       "\x0f".                       // constantFrameRate 0, numTemporalLayers 1, temporalIdNested 1, lengthSizeMinusOne 3
       chr(count($arrays));
    foreach ($arrays as $array) {
        $p .= chr(0x80 | $array[0]).pack('n', count($array[1]));
        foreach ($array[1] as $nalUnit) {
            $p .= pack('n', strlen($nalUnit)).$nalUnit;
        }
    }
    return box('hvcC', $p);
}

// av1C: marker 1, version 1
function av1CBox($profile, $level, $tier, $highBitdepth, $twelveBit, $mono, $ssx, $ssy, $csp, $ipd, $obus) {
    $p = "\x81".chr(($profile << 5) | $level).
       chr(($tier << 7) | ($highBitdepth << 6) | ($twelveBit << 5) | ($mono << 4) |
           ($ssx << 3) | ($ssy << 2) | $csp).
       chr(is_null($ipd) ? 0 : (0x10 | $ipd)).$obus;
    return box('av1C', $p);
}

function ispeBox($width, $height) {
    return fullbox('ispe', 0, 0, pack('NN', $width, $height));
}

function pixiBox($bits) {
    return fullbox('pixi', 0, 0, chr(count($bits)).implode('', array_map('chr', $bits)));
}

function nclxBox($primaries, $transfer, $matrix, $fullRange) {
    return box('colr', 'nclx'.pack('nnn', $primaries, $transfer, $matrix).chr($fullRange << 7));
}

// ICC v2.1 display profile with only a desc tag
function iccProfile($desc) {
    $descTag = 'desc'.pack('N', 0).pack('N', strlen($desc) + 1).$desc."\0".
             pack('NN', 0, 0).pack('n', 0).chr(0).str_repeat("\0", 67);
    $tagOffset = 128 + 4 + 12;
    $header = pack('N', $tagOffset + strlen($descTag))."\0\0\0\0".pack('N', 0x02100000).
            'mntr'.'RGB '.'XYZ '.str_repeat("\0", 12).'acsp'.str_repeat("\0", 4).
            pack('N', 0).pack('NN', 0, 0).pack('NN', 0, 0).pack('N', 0).
            pack('NNN', 0xf6d6, 0x10000, 0xd32d).pack('N', 0).str_repeat("\0", 16 + 28);
    return $header.pack('N', 1).'desc'.pack('NN', $tagOffset, strlen($descTag)).$descTag;
}


// $before . meta . $between . $mdat . $after, where $metaFunc(file offset of $payload in $mdat)
// returns the meta box (its size does not depend on the offset)
function layout($before, $metaFunc, $between, $mdat, $payload, $after = '') {
    $offset = strlen($before) + strlen($metaFunc(0)) + strlen($between) + strpos($mdat, $payload);
    return $before.$metaFunc($offset).$between.$mdat.$after;
}

$files = [];
$ftypHeic = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
$vpsSpsPps = [[32, ["\x40\x01VPS"]], [33, ["\x42\x01SPS"]], [34, ["\x44\x01PPS"]]];

/*
 * items.heic: iloc version 0 with base_offset and several extents, iinf version 0,
 * infe with content_type, iref version 0 (thmb, cdsc), dinf/dref/url, and HEIF properties
 */
$payload = "IMAGEDAT"."THUMB"."EXIF1"."MIME-TEXT";
$files['items.heic'] = layout($ftypHeic, function($p) use ($vpsSpsPps) {
    $iloc = ilocBox(0, [4, 4, 4, 0], [
        [1, 0, 0, 0, [[$p, 0, 8]]],
        [2, 0, 0, $p + 8, [[0, 0, 2], [2, 0, 3]]],
        [3, 0, 0, 0, [[$p + 13, 0, 5]]],
        [4, 0, 0, 0, [[$p + 18, 0, 9]]],
    ]);
    $iinf = iinfBox(0, [infeBox(2, 1, 'hvc1', 'Image'), infeBox(2, 2, 'hvc1', 'Thumbnail'),
                        infeBox(2, 3, 'Exif', ''), infeBox(2, 4, 'mime', 'XMP', 'application/rdf+xml')]);
    $iref = fullbox('iref', 0, 0, refBox('thmb', 2, 2, [1]).refBox('cdsc', 2, 3, [1]).refBox('cdsc', 2, 4, [1]));
    $ipco = box('ipco', hvcCBox($vpsSpsPps, 60, 1, 0).          // 1
                ispeBox(64, 48).                                // 2
                ispeBox(16, 12).                                // 3
                nclxBox(1, 13, 6, 1).                           // 4
                pixiBox([8, 8, 8]).                             // 5
                box('irot', "\x01").                            // 6
                box('pasp', pack('NN', 1, 1)).                  // 7
                box('clap', pack('NNNN', 60, 1, 40, 1).pack('N', -2 & 0xffffffff).pack('NNN', 1, 0, 1))); // 8
    $ipma = ipmaBox(0, 0, [1 => [[1, 1], [0, 2], [0, 4], [0, 5], [1, 6], [0, 7], [1, 8]],
                           2 => [[1, 1], [0, 3]]]);
    $dinf = box('dinf', fullbox('dref', 0, 0, pack('N', 1).fullbox('url ', 0, 1, '')));
    return fullbox('meta', 0, 0, hdlrBox('pict', 'Test').$dinf.fullbox('pitm', 0, 0, pack('n', 1)).
                   $iloc.$iinf.$iref.box('iprp', $ipco.$ipma));
}, '', box('mdat', $payload), $payload);

/*
 * idat_grid.heic: iloc version 1 (construction_method 0 and 1, extent_index, 2-byte length),
 * grid item in idat, iref dimg and auxl, auxC, ipma flags 1 (15-bit property index)
 */
$payload = "TIL1TIL2TIL3TIL4ALPH";
$files['idat_grid.heic'] = layout($ftypHeic, function($p) use ($vpsSpsPps) {
    $items = [[1, 1, 0, 0, [[0, 0, 8]]]];
    for ($i = 0; $i < 4; $i++) {
        $items []= [2 + $i, 0, 0, 0, [[$p + 4 * $i, $i + 1, 4]]];
    }
    $items []= [6, 0, 0, 0, [[$p + 16, 0, 4]]];
    $iloc = ilocBox(1, [4, 2, 0, 4], $items);
    $iinf = iinfBox(0, [infeBox(2, 1, 'grid', 'Grid'), infeBox(2, 2, 'hvc1', ''), infeBox(2, 3, 'hvc1', ''),
                        infeBox(2, 4, 'hvc1', ''), infeBox(2, 5, 'hvc1', ''), infeBox(2, 6, 'hvc1', 'Alpha')]);
    $iref = fullbox('iref', 0, 0, refBox('dimg', 2, 1, [2, 3, 4, 5]).refBox('auxl', 2, 6, [1]));
    $ipco = box('ipco', hvcCBox($vpsSpsPps, 60, 1, 0).                       // 1
                ispeBox(32, 24).                                             // 2
                ispeBox(16, 12).                                             // 3
                fullbox('auxC', 0, 0, "urn:mpeg:hevc:2015:auxid:1\0"."\x01\x02")); // 4
    $ipma = ipmaBox(0, 1, [1 => [[0, 2]], 2 => [[1, 1], [0, 3]], 3 => [[1, 1], [0, 3]],
                           4 => [[1, 1], [0, 3]], 5 => [[1, 1], [0, 3]], 6 => [[1, 1], [0, 3], [1, 4]]]);
    // ImageGrid: version 0, flags 0 (16-bit size), rows 2, columns 2, 32x24
    $idat = box('idat', "\x00\x00\x01\x01".pack('nn', 32, 24));
    return fullbox('meta', 0, 0, hdlrBox('pict', '').fullbox('pitm', 0, 0, pack('n', 1)).
                   $iloc.$iinf.$iref.box('iprp', $ipco.$ipma).$idat);
}, '', box('mdat', $payload), $payload);

/*
 * wide.heif: iloc version 2 with 8-byte offset, length and base_offset, iinf version 1,
 * infe version 3, iref version 1, ipma version 1 flags 1, av1C, unknown property (zzzz)
 * and box (uuid), mdat with largesize, and a last box with size 0
 */
$payload = "AV01"."EXI"."FDA";
$files['wide.heif'] = layout(box('ftyp', 'mif1'.pack('N', 0).'mif1miaf'), function($p) {
    $iloc = ilocBox(2, [8, 8, 8, 0], [
        [2, 0, 0, $p, [[0, 0, 4]]],
        [0x10001, 0, 0, 0, [[$p + 4, 0, 3], [$p + 7, 0, 3]]],
    ]);
    $iinf = iinfBox(1, [infeBox(2, 2, 'av01', 'Image'), infeBox(3, 0x10001, 'Exif', 'ExifData')]);
    $iref = fullbox('iref', 1, 0, refBox('cdsc', 4, 0x10001, [2]));
    $ipco = box('ipco', av1CBox(0, 8, 0, 1, 0, 0, 1, 1, 0, 5, "\x0a\x0b\x00\x00\x00\x24\xc4\xff\xdf\x00\x68\x02").
                ispeBox(8, 8).pixiBox([10, 10, 10]).box('zzzz', "\xde\xad\xbe\xef"));
    $ipma = ipmaBox(1, 1, [2 => [[1, 1], [0, 2], [0, 3], [0, 4]]]);
    return fullbox('meta', 0, 0, hdlrBox('pict', 'AVIF').fullbox('pitm', 0, 0, pack('n', 2)).
                   $iloc.$iinf.$iref.box('iprp', $ipco.$ipma));
}, box('uuid', str_repeat("\x11", 16)."user"), largebox('mdat', $payload), $payload, pack('N', 0).'free'.'tail');

/*
 * movie.mp4: moov / trak / mdia with mvhd version 1, tkhd version 1, hdlr and unparsed boxes
 */
$mvhd = fullbox('mvhd', 1, 0, pack('JJNJ', 0x100000000, 0x100000001, 600, 0x123456789).
                pack('Nn', 0x10000, 0x100).pack('n', 0).str_repeat("\0", 8).
                pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000).
                str_repeat("\0", 24).pack('N', 2));
$tkhd = fullbox('tkhd', 1, 7, pack('JJNNJ', 0x100000000, 0x100000001, 1, 0, 0x123456789).
                str_repeat("\0", 8).pack('nnnn', 0xffff, 2, 0, 0).
                pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000).
                pack('NN', 320 << 16, 240 << 16));
$mdia = box('mdia', fullbox('mdhd', 0, 0, pack('NNNN', 0, 0, 600, 1200).pack('nn', 0x55c4, 0)).
            hdlrBox('vide', 'VideoHandler').box('minf', 'xxxx'));
$files['movie.mp4'] = box('ftyp', 'isom'.pack('N', 0x200).'isommp41').
                    box('moov', $mvhd.box('trak', $tkhd.$mdia).box('udta', 'yyyy')).
                    box('mdat', 'FRAME');

/*
 * truncated.heic: items.heic cut in the middle of meta
 */
$files['truncated.heic'] = substr($files['items.heic'], 0, strlen($ftypHeic) + 100);

/*
 * tiny.icc: ICC profile (version 2.1) with the description "Tiny Test"
 */
$files['tiny.icc'] = iccProfile('Tiny Test');

foreach ($files as $name => $data) {
    file_put_contents("$dir/$name", $data);
    echo "$name: ".strlen($data).PHP_EOL;
}
