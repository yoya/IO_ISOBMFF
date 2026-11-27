IO_ISOBMFF
==========

English version: [README.en.md](README.en.md)

ISOBMFF (ISO Base Media File Format, ISO/IEC 14496-12) のバイナリを解釈/編集する為のライブラリです。
主に HEIF (HEIC) と AVIF の画像を対象にしていて、box の木のダンプ、box の削除、ICC プロファイルの追加、書き出しができます。
書き出すときは、mdat が動いた分だけ iloc のオフセットを書き直します。
IO_Bit と IO_ICC が必要です。IO_HEIF と IO_AVIF はこれを継承しています。
利用に制限はかけません。コピーも改変もご自由にどうぞ。MIT ライセンスにしました。
自分の環境だと動かないとか、機能が欲しいとかいった要望があれば連絡下さい。

# License

MIT License

# Install

```
% composer require yoya/io_isobmff
```

# Usage

- isobmffdump.php (box の木のダンプ。`-t` で type だけ、`-h` で 16 進ダンプ付き、`-f -` で標準入力から読む)

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

- isobmfftree.php (property と item の一覧。item ごとに、役割・データの位置・先頭のバイト・結びついた property を出す)

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

`[1?]` の `?` は essential でない property を表します。

- isobmffitems.php / isobmffprops.php (item ID / property 番号を `-i` で指定して表示。`-t` で一覧)

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

- isobmffrebuild.php / isobmffdelbox.php / isobmffaddiccp.php (作り直し / box の削除 / ICC プロファイルの追加)

```
% php vendor/yoya/io_isobmff/sample/isobmffrebuild.php -f input.avif > output.avif
% php vendor/yoya/io_isobmff/sample/isobmffdelbox.php -f input.avif -t iref > output.avif
% php vendor/yoya/io_isobmff/sample/isobmffaddiccp.php -f input.avif -p sRGB.icc > output.avif
```

- PHP から使う

```php
require_once 'IO/ISOBMFF.php';

$isobmff = new IO_ISOBMFF();
$isobmff->parse(file_get_contents('input.avif'));

// box を種類で探す (木全体から)
foreach ($isobmff->getBoxesByTypes(["ispe"]) as $box) {
    echo $box["width"], "x", $box["height"], PHP_EOL;   // 3840x2160
}
// item 1 に結びついた property の box
foreach ($isobmff->getPropBoxesByItemID(1) as $box) {
    echo $box["type"], " ";                             // ispe colr av1C pixi
}
echo PHP_EOL;

// 編集して書き出す (mdat が動いた分、iloc のオフセットを書き直す)
$isobmff->removeBoxByType(["iref"]);
file_put_contents('output.avif', $isobmff->build());
```

# 設計概要

| クラス・メソッド | 役割 |
|---|---|
| `IO_ISOBMFF` | ファイル全体。box の木 (`boxTree`) を持ち、parse / build / dump する |
| `parse($data, $opts)` / `build($opts)` | 解釈 / 書き出し。`$opts["restrict"]` を付けると、予約ビットや box の長さの食い違いを警告でなく例外にする |
| `dump($opts)` / `dumpBox($box, $opts)` | box のダンプ (`$opts` の `hexdump`, `typeonly`) |
| `tree()` / `analyze()` | property と item の一覧 (`propTree`, `itemTree`) を作る / 表示する |
| `getBoxesByTypes($types)` | 指定した種類の box を木全体から集める |
| `getItemBoxesByItemID($id)` / `getPropBoxesByItemID($id)` / `getPropBoxesByPropIndex($index)` | item に関わる box (infe, pitm, iref の子) / item に結びついた property / ipco の n 番目 (1 から) の property |
| `removeBoxByType($types)` | 指定した種類の box を木から消す (ipco の property を消すと、ipma の結びつきも消して番号を詰める) |
| `appendICCProfile($iccData)` | ipco に `colr` (prof) を足し、ipma の全 item に結びつける |
| `IO_Bit`, `IO_ICC` (別パッケージ) | ビット単位の読み書き / colr の ICC プロファイルの表示 |

- box は連想配列で、`type`, `_offset`, `_length` と解釈した値を持つ。子を持つ box (moov, trak, mdia, meta, dinf, iprp, ipco, iinf, iref, dref) は `boxList` に子を持つ。
- 中身を解釈する box: ftyp, hdlr, mvhd, tkhd, pitm, iloc, iinf, infe, iref (thmb, cdsc, dimg, auxl), ipma, ispe, pasp, hvcC, av1C, colr, pixi, clap, irot, auxC, dref, url 。
- build は解釈した値から box を作り直す。作り直さない種類 (mvhd, tkhd, auxC, 未知の box) は元のバイト列をそのまま書く。
- iloc と mdat の対応: parse 時、iloc の item のオフセット (base_offset が 0 より大きければそれ、でなければ各 extent の extent_offset) が指す mdat に `_mdatId` を付け、
  item (base_offset か最初の extent) と各 extent に mdat 先頭からの相対位置 (`_offsetRelative`) を覚える。
  build 時、mdat の新しい位置から iloc のオフセットの欄を書き直す。idat の中の item (construction_method 1) と外部参照の item は書き直さない。
- サイズ 0 (ファイルの終わりまで) の box も読める (親の box の中なら親の終わりまで)。build は、それが最上位の最後の box のままならサイズ 0 で書き、そうでなければサイズを書く。
- box の version による欄の幅の違い (iloc v2, iinf v1, infe v3, iref v1, ipma v1 の 32bit の ID や個数) に対応している。

# 機能

| 機能 | サンプル (`sample/`) |
|---|---|
| box の木のダンプ | `isobmffdump.php` |
| property と item の一覧 | `isobmfftree.php` |
| item / property ごとの表示 | `isobmffitems.php`, `isobmffprops.php` |
| 作り直し | `isobmffrebuild.php` |
| box の削除 | `isobmffdelbox.php` |
| ICC プロファイルの追加 | `isobmffaddiccp.php` |

# 既知の制限

- base_offset を使う item の extent が複数の mdat にまたがると、build で正しく移せない (書き直すのは base_offset だけ)。
- `removeBoxByType()` が直すのは ipma の property の番号だけ。例えば infe を消しても iloc や iref の item はそのまま残る。

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

## IO_Bit の利用箇所

`buildBox()` は box の内容を書いてサイズを求め、`setUI32BE()` でヘッダの長さを後から埋めます。

[IO_Bit Wiki](https://github.com/yoya/IO_Bit/wiki)
