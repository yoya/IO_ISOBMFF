IO_ISOBMFF
==========

This is a library for interpreting/editing ISOBMFF (ISO Base Media File Format, ISO/IEC 14496-12) binaries.
It mainly targets HEIF (HEIC) and AVIF images: it can dump the box tree, delete boxes, add an ICC profile, and write the file back.
When writing, it rewrites the iloc offsets by the distance the mdat moved.
It requires IO_Bit and IO_ICC. IO_HEIF and IO_AVIF extend it.
Not limited in use, free to copy and modify by MIT license.
Please contact me if it doesn't work in your environment or you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_isobmff
```

# Usage

- isobmffdump.php (box tree dump. `-t` for types only, `-h` with a hex dump, `-f -` reads standard input)

```
% php vendor/yoya/io_isobmff/sample/isobmffdump.php -f input.avif
|
type:ftyp(offset:0 len:32):File Type and compatibility
  major:avif minor:0  alt:mif1, avif, miaf, MA1B
|
type:meta(offset:32 len:305):Information about items
  version:0 flags:0
|-  type:hdlr(offset:44 len:33):Handler reference
(omit)
|-  type:iloc(offset:147 len:44):Item Location
      version:0 flags:0  offsetSize:4 lengthSize:4 baseOffsetSize:0
      itemCount:2
        itemID:1 dataReferenceIndex:0 baseOffset:0
        extentCount:1
          extentOffset:409 extentLength:30980
(omit)
|-|-|-      type:av1C(offset:246 len:26):
              marker:1 version:1 seq_profile:0 seq_level_idx_0:13
              seq_tier_0:0 high_bitdepth:0 twelve_bit:0 monochrome:0
              chroma_subsampling_x:1 chroma_subsampling_y:1
              chroma_sample_position:0 initial_presentation_delay_present:0
              configOBUs: 0a 0c 00 00 00 6a ef bf e1 bd ff f9 80 40
(omit)
```

- isobmfftree.php (list of properties and items. For each item: its role, data location, first bytes and associated properties)

```
% php vendor/yoya/io_isobmff/sample/isobmfftree.php -f input.avif
Props:
[1]: ispe width:3840 height:2160
[2]: colr subtype:nclx
[3]: av1C
[4]: pixi channels:8,8,8
Items:
[1]: pitm type:av01 ref:0 offset:409 length:30980
    (mdat:0x199,0x7904) 0A 0C 00 00 00 6A EF BF E1 BD FF F9 80 40 32 F2 ...
    [1?]ispe:3840,2160 [2?]colr:nclx [3]av1C [4?]pixi:8,8,8 
[2]: cdsc:1 type:Exif ref:0 offset:31389 length:216
    (mdat:0x7a9d,0xd8) 00 00 00 00 49 49 2A 00 08 00 00 00 02 00 98 82 ...
Warning:
```

The `?` in `[1?]` marks a non-essential property.

- isobmffitems.php / isobmffprops.php (show an item ID / property index given with `-i`. `-t` for a list)

```
% php vendor/yoya/io_isobmff/sample/isobmffitems.php -f input.avif -t
pitm  itemID:1
infe  type:av01  itemID:1
infe  type:Exif  itemID:2
cdsc  itemID:2
% php vendor/yoya/io_isobmff/sample/isobmffprops.php -f input.avif -t
ispe  index:1
colr  index:2  nclx
av1C  index:3
pixi  index:4
```

- isobmffrebuild.php / isobmffdelbox.php / isobmffaddiccp.php (rebuild / delete boxes / add an ICC profile)

```
% php vendor/yoya/io_isobmff/sample/isobmffrebuild.php -f input.avif > output.avif
% php vendor/yoya/io_isobmff/sample/isobmffdelbox.php -f input.avif -t iref > output.avif
% php vendor/yoya/io_isobmff/sample/isobmffaddiccp.php -f input.avif -p sRGB.icc > output.avif
```

- From PHP

```php
require_once 'IO/ISOBMFF.php';

$isobmff = new IO_ISOBMFF();
$isobmff->parse(file_get_contents('input.avif'));

// find boxes by type (in the whole tree)
foreach ($isobmff->getBoxesByTypes(["ispe"]) as $box) {
    echo $box["width"], "x", $box["height"], PHP_EOL;   // 3840x2160
}
// property boxes associated with item 1
foreach ($isobmff->getPropBoxesByItemID(1) as $box) {
    echo $box["type"], " ";                             // ispe colr av1C pixi
}
echo PHP_EOL;

// edit and write (iloc offsets are rewritten by the distance the mdat moved)
$isobmff->removeBoxByType(["iref"]);
file_put_contents('output.avif', $isobmff->build());
```

# Design

| Class / method | Role |
|---|---|
| `IO_ISOBMFF` | The whole file. Holds the box tree (`boxTree`); parse / build / dump |
| `parse($data, $opts)` / `build($opts)` | Interpret / write. With `$opts["restrict"]`, reserved-bit and box-length mismatches throw instead of warning |
| `dump($opts)` / `dumpBox($box, $opts)` | Box dump (`hexdump`, `typeonly` in `$opts`) |
| `tree()` / `analyze()` | Build / print the property and item lists (`propTree`, `itemTree`) |
| `getBoxesByTypes($types)` | Collect boxes of the given types from the whole tree |
| `getItemBoxesByItemID($id)` / `getPropBoxesByItemID($id)` / `getPropBoxesByPropIndex($index)` | Boxes about an item (infe, pitm, iref children) / properties associated with an item / the n-th (from 1) property in ipco |
| `removeBoxByType($types)` | Remove boxes of the given types from the tree (removing an ipco property also removes its ipma associations and renumbers the indices) |
| `appendICCProfile($iccData)` | Add a `colr` (prof) to ipco and associate it with every item in ipma |
| `IO_Bit`, `IO_ICC` (separate packages) | Bit-level reading and writing / printing the ICC profile in colr |

- A box is an associative array with `type`, `_offset`, `_length` and the parsed values. Boxes with children (moov, trak, mdia, meta, dinf, iprp, ipco, iinf, iref, dref) hold them in `boxList`.
- Boxes whose content is parsed: ftyp, hdlr, mvhd, tkhd, pitm, iloc, iinf, infe, iref (thmb, cdsc, dimg, auxl), ipma, ispe, pasp, hvcC, av1C, colr, pixi, clap, irot, auxC, dref, url .
- build rebuilds boxes from the parsed values. Types it does not rebuild (mvhd, tkhd, auxC, unknown boxes) are written as the original bytes.
- iloc to mdat link: at parse, the mdat that an iloc item offset (base_offset if it is greater than 0, otherwise the extent_offset of each extent) points into gets an `_mdatId`,
  and the item (base_offset or the first extent) and each extent remember their position relative to the mdat start (`_offsetRelative`).
  At build, the iloc offset fields are rewritten from the new mdat position. Items in idat (construction_method 1) and externally referenced items are not rewritten.
- Boxes with size 0 (to the end of the file) are read (to the end of the parent inside another box). build writes size 0 again if the box is still the last top-level box, otherwise it writes the size.
- Field widths that depend on the box version are handled (32-bit IDs and counts of iloc v2, iinf v1, infe v3, iref v1, ipma v1).

# Features

| Feature | Sample (`sample/`) |
|---|---|
| Box tree dump | `isobmffdump.php` |
| Property and item list | `isobmfftree.php` |
| Per item / per property display | `isobmffitems.php`, `isobmffprops.php` |
| Rebuild | `isobmffrebuild.php` |
| Delete boxes | `isobmffdelbox.php` |
| Add an ICC profile | `isobmffaddiccp.php` |

# Known limitations

- Items that use base_offset and whose extents span several mdats are not moved correctly by build (only base_offset is rewritten).
- `removeBoxByType()` fixes only the ipma property indices. For example, removing an infe leaves the item in iloc and iref.

# Test

```
% php tests/run.php
```

# Reference

- ISO/IEC 14496-12 (ISO Base Media File Format)
- ISO/IEC 23008-12 (HEIF)
- https://aomediacodec.github.io/av1-avif/
- https://developer.apple.com/standards/qtff-2001.pdf

# Derived classes

- https://github.com/yoya/IO_HEIF
- https://github.com/yoya/IO_AVIF
