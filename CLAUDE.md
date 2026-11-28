# IO_ISOBMFF

ISOBMFF (HEIF/AVIF など) のバイナリを解釈・編集する PHP ライブラリ (IO_Bit と IO_ICC に依存)。本体は [IO/ISOBMFF.php](IO/ISOBMFF.php)。

# 互換性

IO_HEIF、IO_AVIF、IO_F4V が `IO_ISOBMFF` を継承している (IO_HEIF と IO_AVIF は composer.json で依存、IO_F4V は composer.json が無く `require_once 'IO/ISOBMFF.php'` だけ)。

- IO_HEIF ([../IO_HEIF/IO/HEIF.php](../IO_HEIF/IO/HEIF.php)) は `boxTree`, `_isobmffData`, `getBoxesByTypes`, `analyze` / `itemTree`, `getPropBoxesByItemID` などを直接使い、box の連想配列のキー
  (iloc の `itemArray` / `extentArray` / `baseOffset`、ipma の `entryArray`、hvcC の `nalArrays`、auxC の `auxType`、mdat の `data` / `_mdatId` / `_offsetRelative`) を読み書きする。
  `fromHEVC()` は `_offset` の無い box の木を一から組み立てて `build()` に渡す。
- IO_AVIF と IO_F4V は何も足していない。サンプルが `parse`, `dump`, `tree`, `build`, `removeBoxByType`, `appendICCProfile` を使う。

- 既存の挙動を変える修正は、依存側 (`../IO_*`) での使われ方を grep で確かめてから行う。
- 明らかなバグ修正以外で挙動が変わるもの (box の配列のキー名、build の出力、dump の形式、入力検査など) は、メジャーバージョンを上げて扱う。

# テスト

`php tests/run.php` で回帰テストを実行する (失敗すると終了コード 1)。
IO_Bit と IO_ICC は vendor/ (composer install) か include_path から読む。例: `php -d include_path=.:../IO_Bit:../IO_ICC tests/run.php`。
挙動を変えたら IO_HEIF と IO_AVIF のテストも回す。例: `cd ../IO_HEIF && php -d include_path=.:../IO_Bit:../IO_ICC:../IO_ISOBMFF:../IO_HEVC tests/run.php`。
バグを直したら、ここにテストを足す。

# リリース作業

バージョン番号は指示されたものを使う。進め方は過去のリリースの形に倣い、迷う点や手順に無い気づきがあれば報告する。

1. 変更の把握
   - 手元が公開済みの状態と揃っていて、リリースに含めない変更が混じっていないことを確かめる。
   - 前回のタグから何が変わったかを、コミットの本文まで読んで把握する。
2. 動作の確認
   - `php tests/run.php` が通ることを確かめる。挙動を変えた箇所があれば、IO_HEIF と IO_AVIF で壊れないことも確かめる。
3. README の更新
   - README.md と README.en.md が、今回の変更を含めた現状を表しているか見直し、必要なら直す。両者の内容は揃える。
   - 直す必要が無ければ、そう判断したことを報告する。
   - 直したものは、バージョン番号の変更とは別のコミットにする。
4. バージョン番号の更新
   - [IO/ISOBMFF.php](IO/ISOBMFF.php) 冒頭の `IO_ISOBMFF class - vX.Y` を新しい番号にし、それだけのコミットにする。
   - メッセージは `vX.Y => vA.B` の形にする (例: `v3.0 => v4.0`)。
5. タグ
   - v なしでタグを打ち、ブランチと一緒に公開する。通常は `4.1` のように 2 桁、修正だけの版は `4.1.1` のように 3 桁。
6. GitHub リリース
   - 4.0 から、版ごとに GitHub リリースを作っている。
   - タイトルは変更の要約にし、利用者が読んで分かる言葉で書く。ノートの言葉と形は前回のリリースに合わせる。
7. Wiki
   - Wiki は別リポジトリ (`git@github.com:yoya/IO_ISOBMFF.wiki.git`)。Home (紹介・インストール・ページ一覧)、Design (設計・実装詳細・既知の制限)、Usage (コード例)、API (メソッド一覧)、Samples (sample/ のスクリプトの使い方) のページがある。
   - Format (ファイル形式の解説。入門から実務までの 4 段階、図は Wiki の images/ の SVG) のページもある。形式の扱いを変えたら (読めるようになった版や拡張、直した制限など)、その記述も直す。
   - 今回の変更で古くなった記述が無いか見て、あれば直す (既知の制限に書いた不具合を直したら、その行も消す)。README と食い違わないようにする。
8. 反映の確認
   - Packagist (`yoya/io_isobmff`) に新しい版が反映されていることを確かめる (リリースを作ったなら、最新版として見えていることも)。

## 依存の順序

Packagist には依存される側から出す。

- io_icc を io_isobmff より先に出す (io_isobmff は `yoya/io_icc` に依存する)。
- io_isobmff と io_hevc を io_heif より先に、io_isobmff を io_avif より先に出す。
- メジャーバージョンを上げたら、依存側の composer.json の制約がその新しいメジャーを許すように直してから依存側を出す。
  今は IO_AVIF が `"~2.0 || ^4.0"`、IO_HEIF が `"~2.6 || ^4.0"` を要求している。

公開する文書 (コミットメッセージ、リリースノート、README、Wiki) には、`./tmp/` 由来のファイル名や中身を入れない。
