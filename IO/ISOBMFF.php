<?php

/*
  IO_ISOBMFF class - v2.3
  (c) 2017/07/26 yoya@awm.jp
  ref) https://developer.apple.com/standards/qtff-2001.pdf
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
    require_once 'IO/ICC.php';
}

function getTypeDescription($type) {
    // http://mp4ra.org/atoms.html
    // https://developer.apple.com/videos/play/wwdc2017/513/
    static $getTypeDescriptionTable = [
        "ftyp" => "File Type and compatibility",
        "meta" => "Information about items",
        "mdat" => "Media Data",
        "moov" => "Movie Box",
        //
        "hdlr" => "Handler reference",
        "pitm" => "Prinary Item reference",
        "iloc" => "Item Location",
        "iinf" => "Item Information",
        "infe" => "Item Information Entry",
        //
        "dinf" => "Data Information Box",
        "dref" => "Data Reference Box",
        "url " => "Data Entry Url Box",
        "urn " => "Data Entry Urn Box",
        //
        "iref" => "Item Reference Box",
        "dimg" => "Derived Image",
        "thmb" => "Thumbnail",
        "auxl" => "Auxiliary image",
        "cdsc" => "Content Description",
        //
        "iprp" => "Item Properties",
        "ipco" => "Item Property Container",
        "pasp" => "Pixel Aspect Ratio",
        "hvcC" => "HEVC Decoder Conf",
        "ispe" => "Image Spatial Extents", // width, height
        "colr" => "Colour Information", // ICC profile
        "pixi" => "Pixel Information",
        "clap" => "Clean Aperture",
        "irot" => "Image Rotation",
        //
        "ipma" => "Item Properties Association",
    ];
    if (isset($getTypeDescriptionTable[$type])) {
        return $getTypeDescriptionTable[$type];
    }
    return null;
}

function getChromeFormatDescription($format) {
    static $chromeFormatDescription = [
        0 => "Grayscale",
        1 => "YUV420",
        2 => "YUV422",
        3 => "YUV444",
    ];
    if (isset($chromeFormatDescription[$format])) {
        return $chromeFormatDescription[$format];
    }
    return "Unknown Chroma Format";
}

// https://www.itu.int/rec/T-REC-H.265-201304-S
function getProfileIdcDescription($idc) {
    static $profileIdcDescription = [
        1 => "Main profile",
        2 => "Main 10 profile",
        3 => "Main Still Picture profile",
    ];
    if (isset($profileIdcDescription[$idc])) {
        return $profileIdcDescription[$idc];
    }
    return "Unknown Profile";
}

class IO_ISOBMFF {
    var $_chunkList = null;
    var $_isobmffData = null;
    var $boxTree = [];
    var $propTree = null;
    var $itemTree = null;
    function parse($isobmffData, $opts = array()) {
        $opts["indent"] = 0;
        $bit = new IO_Bit();
        $bit->input($isobmffData);
        $this->_isobmffData = $isobmffData;
        $this->boxTree = $this->parseBoxList($bit, strlen($isobmffData), null, $opts);
        // offset linking iloc=baseOffset <=> mdat
        $this->applyFunctionToBoxTree2($this->boxTree, function(&$iloc, &$mdat) {
            if (($iloc["type"] !== "iloc") || ($mdat["type"] !== "mdat")) {
                return ;
            }
            foreach ($iloc["itemArray"] as &$item) {
                $itemID = $item["itemID"];
                if (isset($item["baseOffset"])) {
                    if ($item["baseOffset"] > 0) {
                        $offset = $item["baseOffset"];
                    } else {
                        $offset = $item["extentArray"][0]["extentOffset"];
                    }
                    $mdatStart = $mdat["_offset"];
                    $mdatNext = $mdatStart + $mdat["_length"];
                    if (($mdatStart <= $offset) && ($offset < $mdatNext)) {
                        $mdatId = mt_rand();
                        $item["_mdatId"] = $mdatId;
                        $mdat["_mdatId"] = $mdatId;
                        $offsetRelative = $offset - $mdatStart;
                        $item["_offsetRelative"] = $offsetRelative;
                        $mdat["_offsetRelative"] = $offsetRelative;
                        $mdat["_itemID"] = $itemID;
                    }
                }
            }
            unset($item);
        });
    }
    //
    function applyFunctionToBoxTree(&$boxTree, $callback, &$userdata) {
        foreach ($boxTree as &$box) {
            $callback($box, $userdata);
            if (isset($box["boxList"])) {
                $this->applyFunctionToBoxTree($box["boxList"], $callback, $userdata);
            }
        }
        unset($box);
    }
    // combination traversal
    function applyFunctionToBoxTree2(&$boxTree, $callback) {
        foreach ($boxTree as &$box) {
            $this->applyFunctionToBoxTree($boxTree, $callback, $box);
            if (isset($box["boxList"])) {
                $this->applyFunctionToBoxTree2($box["boxList"], $callback);
            }
        }
        unset($box);
    }
    function parseBoxList($bit, $length, $parentType, $opts) {
        // echo "parseBoxList(".strlen($data).")\n";
        $boxList = [];
        $opts["indent"] = $opts["indent"] + 1;
        list($boxOffset, $dummy) = $bit->getOffset();
        while ($bit->hasNextData(8) && ($bit->getOffset()[0] < ($boxOffset + $length))) {
            try {
                $type = str_split($bit->getData(8), 4)[1];
                $bit->incrementOffset(-8, 0);
                $box = $this->parseBox($bit, $parentType, $opts);
            } catch (Exception $e) {
                fwrite(STDERR, "ERROR type:$type".PHP_EOL);
                throw $e;
            }
            $boxList []= $box;
        }
        return $boxList;
    }
    
    function parseBox($bit, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        $indentSpace = str_repeat("    ", $opts["indent"] - 1);
        $boxLength = $bit->getUI32BE();
        if ($boxLength <= 1) {
            $boxLength = null;
        } else if ($boxLength < 8) {
            list($offset, $dummy) = $bit->getOffset();
            throw new Exception("parseBox: boxLength($boxLength) < 8 (fileOffset:$offset)");
        }
        $type = $bit->getData(4);
        $box = ["type" => $type, "_offset" => $boxOffset, "_length" => $boxLength];
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: parseBox:$indentSpace type:$type offset:$boxOffset boxLength:$boxLength\n");
        }
        if ($boxLength && ($bit->hasNextData($boxLength - 8) === false)) {
            list($offset, $dummy) = $bit->getOffset();
            throw new Exception("parseBox: hasNext(boxLength:$boxLength - 8) === false (boxOffset:$boxOffset) (fileOffset:$offset)");
        }
        if ($boxLength) {
            $nextOffset = $boxOffset + $boxLength;
            $dataLen = $boxLength - 8; // 8 = len(4) + type(4)
        } else {
            $nextOffset = null;
            $dataLen = null;
        }
        switch($type) {
        case "ftyp":
            $box["major"] = $bit->getData(4);
            $box["minor"] = $bit->getUI32BE();
            $altdata = $bit->getData($dataLen - 8);
            $box["alt"] = str_split($altdata, 4);
            break;
        case "hdlr":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["componentType"] = $bit->getData(4);
            $box["componentSubType"] = $bit->getData(4);
            $box["componentManufacturer"] = $bit->getData(4);
            $box["componentFlags"] = $bit->getUI32BE();
            $box["componentFlagsMask"] = $bit->getUI32BE();
            $box["componentName"] = $bit->getData($dataLen - 24);
            break;
        case "mvhd": // ISO/IEC 14496-12:2005(E)
            $boxVersion = $bit->getUI8();
            $box["version"] = $boxVersion;
            $box["flags"] = $bit->getUIBits(8 * 3);
            if ($boxVersion == 0) {
                $box["creationTime"] = $bit->getUI32BE();
                $box["modificationTime"] = $bit->getUI32BE();
                $box["timeScale"] = $bit->getUI32BE();
                $box["duration"] = $bit->getUI32BE();
            } else if ($boxVersion == 1) {
                $box["creationTime"] = $bit->getUI64BE();
                $box["modificationTime"] = $bit->getUI64BE();
                $box["timeScale"] = $bit->getUI32BE();
                $box["duration"] = $bit->getUI64BE();
            } else {
                $mesg = "mvhd box version:$boxVersion != 0,1";
                throw new Exception($mesg);
            }
            $box["preferredRate"] = $bit->getUI32BE();
            $box["preferredVolume"] = $bit->getUI16BE();
            $box["reserved1"] = $bit->getUI16BE();
            $box["reserved2"] = $bit->getData(8);
            $matrix = [];
            foreach (range(0, 8) as $i) {
                $matrix []= $bit->getSI32BE(); // XXX: SI ? UI ?
            }
            $box["matrix"] = $matrix;
            $box["previewTime"] = $bit->getUI32BE();
            $box["peviewDuration"] = $bit->getUI32BE();
            $box["posterTime"] = $bit->getUI32BE();
            $box["selectionTime"] = $bit->getUI32BE();
            $box["selectionDuration"] = $bit->getUI32BE();
            $box["currentTime"] = $bit->getUI32BE();
            $box["nextTrackID"] = $bit->getUI32BE();
            break;
        case "tkhd":
            $boxVersion = $bit->getUI8();
            $box["version"] = $boxVersion;
            $box["flags"] = $bit->getUIBits(8 * 3);
            if ($boxVersion == 0) {
                $box["creationTime"] = $bit->getUI32BE();
                $box["modificationTime"] = $bit->getUI32BE();
                $box["trackId"] = $bit->getUI32BE();
                $box["reserved"] = $bit->getData(4);
                $box["duration"] = $bit->getUI32BE();
            } else if ($boxVersion == 1) {
                $box["creationTime"] = $bit->getUI64BE();
                $box["modificationTime"] = $bit->getUI64BE();
                $box["trackId"] = $bit->getUI32BE();
                $box["reserved"] = $bit->getData(4);
                $box["duration"] = $bit->getUI64BE();
            } else {
                $mesg = "tkhd box version:$boxVersion != 0,1";
                throw new Exception($mesg);
            }
            $box["reserved"] = $bit->getData(4);
            $box["layer"] = $bit->getUI32BE();
            $box["alternateGroup"] = $bit->getUI32BE();
            $box["volume"] = $bit->getUI16BE();
            $box["reserved"] = $bit->getUI16BE();
            $matrix = [];
            foreach (range(0, 8) as $i) {
                $matrix []= $bit->getSI32BE(); // XXX: SI ? UI ?
            }
            $box["matrix"] = $matrix;
            $box["width"] = $bit->getUI32BE();
            $box["height"] = $bit->getUI32BE();
            break;
        case "ispe":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["width"]  = $bit->getUI32BE();
            $box["height"] = $bit->getUI32BE();
            break;
        case "pasp":
            $box["hspace"] = $bit->getUI32BE();
            $box["vspace"] = $bit->getUI32BE();
            break;
        case "pitm":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["itemID"] = $bit->getUI16BE();
            break;
        case "hvcC":
            // https://gist.github.com/yohhoy/2abc28b611797e7b407ae98faa7430e7
            $box["version"]  = $bit->getUI8();
            $box["profileSpace"] = $bit->getUIBits(2);
            $box["tierFlag"] = $bit->getUIBit();
            $box["profileIdc"] = $bit->getUIBits(5);
            $box["profileCompatibilityFlags"] = $bit->getUI32BE();
            $box["constraintIndicatorFlags"] = $bit->getUIBits(48);
            $box["levelIdc"] = $bit->getUI8();
            $reserved = $bit->getUIBits(4);
            if ($reserved !== 0xF) {
                $mesg = "reserved({$reserved}) !== 0xF at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["minSpatialSegmentationIdc"]  = $bit->getUIBits(12);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                $mesg = "reserved({$reserved}) !== 0x3F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["parallelismType"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                $mesg = "reserved({$reserved}) !== 0x3F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["chromaFormat"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                $mesg = "reserved({$reserved}) !== 0x1F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["bitDepthLumaMinus8"]  = $bit->getUIBits(3);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                $mesg = "reserved({$reserved}) !== 0x1F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["bitDepthChromaMinus8"]  = $bit->getUIBits(3);
            $box["avgFrameRate"]  = $bit->getUIBits(16);
            $box["constantFrameRate"]  = $bit->getUIBits(2);
            $box["numTemporalLayers"]  = $bit->getUIBits(3);
            $box["temporalIdNested"]  = $bit->getUIBit();
            $box["lengthSizeMinusOne"]  = $bit->getUIBits(2);
            
            $box["numOfArrays"] = $numOfArrays = $bit->getUI8();
            $nalArrays = [];
            for ($i = 0 ; $i < $numOfArrays ; $i++) {
                $nal = [];
                $nal["array_completeness"] = $bit->getUIBit();
                $reserved = $bit->getUIBit();
                if ($reserved !== 0) {
                    var_dump($box);
                    var_dump($nalArrays);
                    throw new Exception("reserved({$reserved}) !== 0 at L%d");
                }
                $nal["NALUnitType"] = $bit->getUIBits(6);
                $nal["numNalus"] = $numNalus = $bit->getUI16BE();
                $nalus = [];
                for ($j = 0 ; $j < $numNalus ; $j++) {
                    $nalu = [];
                    $nalu["nalUnitLength"] = $nalUnitLength = $bit->getUI16BE();
                    $nalu["nalUnit"] = $bit->getData($nalUnitLength);
                    $nalus []= $nalu;
                }
                $nal["nalus"] = $nalus;
                $nalArrays []= $nal;
            }
            $box["nalArrays"] = $nalArrays;
           break;
        case "iloc":
            if ($parentType === "iref") {
                $box["itemID"] = $bit->getUI16BE();
                $box["itemCount"] = $bit->getUI16BE();
                $itemArray = [];
                for ($i = 0 ; $i < $box["itemCount"]; $i++) {
                    $item = [];
                    $item["itemID"] = $bit->getUI16BE();
                    $itemArray []= $item;
                }
                $box["itemArray"] = $itemArray;
            } else {
                $box["version"] = $bit->getUI8();
                $box["flags"] = $bit->getUIBits(8 * 3);
                $offsetSize = $bit->getUIBits(4);
                $lengthSize = $bit->getUIBits(4);
                $baseOffsetSize = $bit->getUIBits(4);
                $box["offsetSize"] = $offsetSize;
                $box["lengthSize"] = $lengthSize;
                $box["baseOffsetSize"] = $baseOffsetSize;
                if ($box["version"] === 0) {
                    $box["reserved"] = $bit->getUIBits(4);
                } else {
                    $indexSize = $bit->getUIBits(4);
                    $box["indexSize"] = $indexSize;
                }
                $itemCount = $bit->getUI16BE();
                $box["itemCount"] = $itemCount;
                $itemArray = [];
                for ($i = 0 ; $i < $itemCount; $i++) {
                    $item = [];
                    $item["itemID"] = $bit->getUI16BE();
                    if ($box["version"] >= 1) {
                        $item["constructionMethod"] = $bit->getUI16BE();
                    }
                    $item["dataReferenceIndex"] = $bit->getUI16BE();
                    $item["baseOffset"] = $bit->getUIBits(8 * $baseOffsetSize);
                    $extentCount = $bit->getUI16BE();
                    $item["extentCount"] = $extentCount;
                    $extentArray = [];
                    for ($j = 0 ; $j < $extentCount ; $j++) {
                        $extent = [];
                        $extent["extentOffset"] = $bit->getUIBits(8 * $offsetSize);
                        if ($box["version"] >= 1) {
                            $extent["extentIndex"] = $bit->getUIBits(8 * $indexSize);
                        }
                        $extent["extentLength"] = $bit->getUIBits(8 * $lengthSize);
                        $extentArray [] = $extent;
                    }
                    $item["extentArray"] = $extentArray;
                    $itemArray []= $item;
                }
                $box["itemArray"] = $itemArray;
            }
            break;
        case "iref":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $dataLen -= 4;
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        case "thmb":
        case "cdsc":
        case "dimg":
        case "auxl":
            $box["fromItemID"] = $bit->getUI16BE();
            $box["itemCount"] = $bit->getUI16BE();
            $itemIDArray = [];
            for ($i = 0 ; $i < $box["itemCount"] ; $i++) {
                $item = [];
                $item["itemID"] = $bit->getUI16BE();
                $itemArray []= $item;
            }
            $box["itemArray"] = $itemArray;
            break;
        case "colr":
            $box["subtype"] = $bit->getData(4);
            $dataLen -= 4;
            $box["data"] = $bit->getData($dataLen);
            break;
        case "pixi":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["channelCount"] = $bit->getUI8();
            $channelArray = [];
            for ($i = 0 ; $i < $box["channelCount"] ; $i++) {
                $channelArray []= [ "bitsPerChannel" => $bit->getUI8() ];
            }
            $box["channelArray"] = $channelArray;
            break;
        case "clap":
            $box["width_N"] = $bit->getSI32BE();
            $box["width_D"] = $bit->getSI32BE();
            $box["height_N"] = $bit->getSI32BE();
            $box["height_D"] = $bit->getSI32BE();
            $box["horizOff_N"] = $bit->getSI32BE();
            $box["horizOff_D"] = $bit->getSI32BE();
            $box["vertOff_N"] = $bit->getSI32BE();
            $box["vertOff_D"] = $bit->getSI32BE();
            break;
        case "irot":
            $bit->getUIBits(6); // reserved
            $box["angle"] = $bit->getUIBits(2);
            break;
        case "ipma":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["entryCount"] = $bit->getUI32BE();
            $entryArray = [];
            for ($i = 0 ; $i < $box["entryCount"] ; $i++) {
                $entry = [];
                $entry["itemID"] = $bit->getUI16BE();
                $entry["associationCount"] = $bit->getUI8();
                $associationArray = [];
                for ($j = 0 ; $j < $entry["associationCount"] ; $j++) {
                    $association = [];
                    $association["essential"] = $bit->getUIBit();
                    if ($box["flags"] & 1) {
                        $association["propertyIndex"] = $bit->getUIBits(15);
                    }  else {
                        $association["propertyIndex"] = $bit->getUIBits(7);
                    }
                    $associationArray [] = $association;
                }
                $entry["associationArray"] = $associationArray;
                $entryArray []= $entry;
            }
            $box["entryArray"] = $entryArray;
            break;
        case "iinf":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                $box["count"] = $bit->getUI16BE();
                $dataLen -= 6;
            } else {
                $box["count"] = $bit->getUI32BE();
                $dataLen -= 8;
            }
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        case "infe":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["itemID"] = $bit->getUI16BE();
            $box["itemProtectionIndex"] = $bit->getUI16BE();
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                ;
            } else {
                $box["itemType"] = $bit->getData(4);
            }
            $box["itemName"] = $bit->getDataUntil("\0");
            $box["contentType"] = null;
            $box["contentEncoding"] = null;
            list($offset, $dummy) = $bit->getOffset();
            if (($offset - $boxOffset) < $dataLen) {
                $box["contentType"] = $bit->getDataUntil("\0");
                list($offset, $dummy) = $bit->getOffset();
                if (($offset - $boxOffset) < $dataLen) {
                    $box["contentEncoding"] = $bit->getDataUntil("\0");
                }
            }
            break;
        case "dref":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $entryCount = $bit->getUI32BE();
            $box["entryCount"] = $entryCount;
            $dataLen -= 8;
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            if (count($box["boxList"]) !== $entryCount) {
                throw new Exception("parseBox: box[boxList]:{$box['entryCount']} != entryCount:$entryCount");
            }
            break;
        case "url ":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["location"] = $bit->getData($dataLen - 4);
            break;
        case "auxC":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["auxType"] = $bit->getDataUntil("\0");
            $currOffset = $bit->getOffset()[0];
            if ($currOffset < $nextOffset) {
                $box["auxSubType"] = $bit->getData($nextOffset - $currOffset);
            } else {
                $box["auxSubType"] = null;
            }
            break;
            break;
            /*
             * container type
             */
        case "moov": // Movie Atoms
        case "trak":
        case "mdia":
        case "meta": // Metadata
        case "dinf": // data infomation
        case "iprp": // item properties
        case "ipco": // item property container
            if ($type === "meta") {
                $box["version"] = $bit->getUI8();
                $box["flags"] = $bit->getUIBits(8 * 3);
                $dataLen -= 4;
            }
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        default: // mdat, idat
            $currOffset = $bit->getOffset()[0];
            $bit->getData($nextOffset - $currOffset);
            break;
        }
        if ($boxLength) {
            list($currOffset, $dummy) = $bit->getOffset($nextOffset, 0);
            if ($currOffset != $nextOffset) {
                $mesg = "type:$type(boxLen:$boxLength) currOffset:$currOffset != (box)nextOffset:$nextOffset";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $bit->setOffset($nextOffset, 0);
        } else {
            $bit->getDataUntil(false); // skip to the end
            $currOffset = $bit->getOffset()[0];
            $boxLength = $currOffset - $box["_offset"];
            $box["_length"] = $boxLength;
        }
        return $box;
    }
    function dump($opts = array()) {
        $opts["indent"] = 0;
        $this->dumpBoxList($this->boxTree, $opts);
    }
    function dumpBoxList($boxList, $opts) {
        if (is_array($boxList) === false) {
            echo "dumpBoxList ERROR:";
            var_dump($boxList);
            return ;
        }
        foreach ($boxList as $box) {
            $this->dumpBox($box, $opts);
        }
    }
    function dumpBox($box, $opts) {
        $type = $box["type"];
        $indentSpace = str_repeat("    ", $opts["indent"]);
        if (! empty($opts["typeonly"])) {
            $this->printfBox($box, $indentSpace."type:%s");
            if (isset($box["version"])) {
                $this->printfBox($box, " version:%d");
            }
            if (isset($box["flags"])) {
                $this->printfBox($box, " flags:%d");
            }
            echo PHP_EOL;
            if (isset($box["boxList"])) {
                $opts["indent"] += 1;
                $this->dumpBoxList($box["boxList"], $opts);
            }
            return ;
        }
        if (empty($opts["indent"])) {
            echo "|".PHP_EOL;
        }
        $indentSpaceType = str_repeat("|-", $opts["indent"]) . str_repeat("  ", $opts["indent"]);
        echo $indentSpaceType."type:".$type."(offset:".$box["_offset"]." len:".$box["_length"]."):";
        $desc = getTypeDescription($type);
        if ($desc) {
            echo $desc;
        }
        echo "\n";
        switch ($type) {
        case "ftyp":
            echo $indentSpace."  major:".$box["major"]." minor:".$box["minor"];
            echo "  alt:".join(", ", $box["alt"]).PHP_EOL;
            break;
        case "ispe":
            echo $indentSpace."  version:".$box["version"]." flags:".$box["flags"];
            echo "  width:".$box["width"]." height:".$box["height"].PHP_EOL;
            break;
        case "thmb":
        case "cdsc":
        case "dimg":
        case "auxl":
            $this->printfBox($box, $indentSpace."  fromItemID:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
            foreach ($box["itemArray"] as $item) {
                $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
            }
            break;
        case "colr":
            $this->printfBox($box, $indentSpace."  subtype:%s");
            echo "  data(len:".strlen($box["data"]).")".PHP_EOL;
            break;
        case "pixi":
            $this->printfBox($box, $indentSpace."  channelCount:%d".PHP_EOL);
            foreach ($box["channelArray"] as $item) {
                $this->printfBox($item, $indentSpace."    bitsPerChannel:%d".PHP_EOL);
            }
            break;
        case "clap":
            $this->printfBox($box, $indentSpace."  width_N:%d / width_D:%d  height_N:%d / height_D:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  horizOff_N:%d / horizOff_D:%d  vertOff_N:%d / vertOff_D:%d".PHP_EOL);
            break;
        case "irot":
            $this->printfBox($box, $indentSpace."  angle:%d".PHP_EOL);
            break;
        case "ipma":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            $box["entryCount"] = count($box["entryArray"]);
            $this->printfBox($box, $indentSpace."  entryCount:%d".PHP_EOL);
            foreach ($box["entryArray"] as $entry) {
                $this->printfBox($entry, $indentSpace."    itemID:%d".PHP_EOL);
                $entry["associationCount"]  = count($entry["associationArray"] );
                $this->printfBox($entry, $indentSpace."    associationCount:%d".PHP_EOL);
                foreach ($entry["associationArray"] as $assoc) {
                    $this->printfBox($assoc, $indentSpace."      essential:%d propertyIndex:%d".PHP_EOL);
                }
            }
            break;
        case "infe":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  itemID:%d itemProtectionIndex:%d".PHP_EOL);
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                ;
            } else {
                $this->printfBox($box, $indentSpace."  itemType:%s".PHP_EOL);
            }
            $this->printfBox($box, $indentSpace."  itemName:%s contentType:%s contentEncoding:%s".PHP_EOL);
            break;
        case "url ":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  location:%s".PHP_EOL);
            break;
        case "pasp":
            echo $indentSpace."  hspace:".$box["hspace"]." vspace:".$box["vspace"].PHP_EOL;
            break;
        case "pitm":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  itemID:%d".PHP_EOL);
            break;
        case "hvcC":
            $profileIdc = $box["profileIdc"];
            $profileIdcStr = getProfileIdcDescription($profileIdc);
            $this->printfBox($box, $indentSpace."  version:%d profileSpace:%d tierFlag:%x profileIdc:%d");
            echo "($profileIdcStr)".PHP_EOL;
            $this->printfBox($box, $indentSpace."  profileCompatibilityFlags:0x%x".PHP_EOL);
            $this->printfBox($box, $indentSpace."  constraintIndicatorFlags:0x%x levelIdc:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  minSpatialSegmentationIdc:%d parallelismType:%d".PHP_EOL);
            $chromaFormatStr = getChromeFormatDescription($box["chromaFormat"]);
            $this->printfBox($box, $indentSpace."  chromaFormat:%d($chromaFormatStr) bitDepthLumaMinus8:%d bitDepthChromaMinus8:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  avgFrameRate:%d constantFrameRate:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  numTemporalLayers:%d temporalIdNested:%d lengthSizeMinusOne:%d".PHP_EOL);
            foreach ($box["nalArrays"] as $nal) {
                $this->printfBox($nal, $indentSpace."    array_completeness:%d NALUnitType:%d".PHP_EOL);
                foreach ($nal["nalus"] as $nalu) {
                    $nalu["nalUnitLength"] = strlen($nalu["nalUnit"]);
                    $this->printfBox($nalu, $indentSpace."      nalUnitLength:%d nalUnit:%h".PHP_EOL);
                }
            }
            break;
        case "iloc":
            if (isset($box["version"]) === false) {
                $this->printfBox($box, $indentSpace."  itemID:%d".PHP_EOL);
                $box["itemCount"] = count($box["itemArray"]);
                $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
                foreach ($box["itemArray"] as $item) {
                    $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
                }
            } else {
                if  ($box["version"] === 0) {
                    $this->printfBox($box, $indentSpace."  version:%d flags:%d  offsetSize:%d lengthSize:%d baseOffsetSize:%d".PHP_EOL);
                } else {
                    $this->printfBox($box, $indentSpace."  version:%d flags:%d  offsetSize:%d lengthSize:%d baseOffsetSize:%d indexSize:%d".PHP_EOL);
                }
                $box["itemCount"] = count($box["itemArray"]);
                $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
                foreach ($box["itemArray"] as $item) {
                    if  ($box["version"] === 0) {
                        $this->printfBox($item, $indentSpace."    itemID:%d dataReferenceIndex:%d baseOffset:%d".PHP_EOL);
                    } else {
                        $this->printfBox($item, $indentSpace."    itemID:%d constructionMethod:%d dataReferenceIndex:%d baseOffset:%d".PHP_EOL);
                    }
                    $item["extentCount"]  = count($item["extentArray"]);
                    $this->printfBox($item, $indentSpace."    extentCount:%d".PHP_EOL);
                    foreach ($item["extentArray"] as $extent) {
                        if ($box["version"] === 0) {
                            $this->printfBox($extent, $indentSpace."      extentOffset:%d extentLength:%d".PHP_EOL);
                        } else {
                            $this->printfBox($extent, $indentSpace."      extentOffset:%d extentIndex:%d extentLength:%d".PHP_EOL);
                        }
                    }
                }
            }
            break;
        case "auxC":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  auxType:%s".PHP_EOL);
            $this->printfBox($box, $indentSpace."  auxSubType:%s".PHP_EOL);
            break;
        default:
            $box2 = [];
            foreach ($box as $key => $data) {
                if (in_array($key, ["type", "(len)", "boxList", "_offset", "_length", "version", "flags"]) === false) {
                    $box2[$key] = $data;
                }
            }
            if (isset($box["version"])) {
                $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            }
            $this->printTableRecursive($indentSpace."  ", $box2);
            break;
        }
        if (isset($box["boxList"])) {
            if (! empty($opts['hexdump'])) {
                $bit = new IO_Bit();
                $bit->input($this->_isobmffData);
                $offset = $box["_offset"];
                $length = $box["boxList"][0]["_offset"] - $offset;
                $bit->hexdump($offset, $length);
            }
            $opts["indent"] += 1;
            $this->dumpBoxList($box["boxList"], $opts);
        } else {
            if (! empty($opts['hexdump'])) {
                $bit = new IO_Bit();
                $bit->input($this->_isobmffData);
                $bit->hexdump($box["_offset"], $box["_length"]);
            }
        }
    }
    function printTableRecursive($indentSpace, $table) {
        foreach ($table as $key => $value) {
            if (is_array($value)) {
                echo $indentSpace."$key:\n";
                $this->printTableRecursive($indentSpace."  ", $value);
            } else {
                echo $indentSpace."$key:$value\n";
            }
        }
    }

    function printfBox($box, $format) {
        preg_match_all('/(\S+:[^%]*%\S+|\s+)/', $format, $matches);
        foreach ($matches[1] as $match) {
            if (preg_match('/(\S+):([^%]*)(%\S+)/', $match , $m)) {
                $f = $m[3];
                if ($f === "%h") {
                    printf($m[1].":".$m[2]);
                    foreach (str_split($box[$m[1]]) as $c) {
                        printf(" %02x", ord($c));
                    }
                } else {
                    printf($m[1].":".$m[2].$f, $box[$m[1]]);
                }
            } else {
                echo $match;
            }
        }
    }
    function removeBoxByType($removeTypeList) {
        $this->boxTree = $this->removeBoxByType_r($this->boxTree, $removeTypeList);
        // update baseOffset in iloc Box
    }
    function removeBoxByType_r($boxList, $removeTypeList) {
        foreach ($boxList as $idx => $box) {
            if (in_array($box["type"], $removeTypeList)) {
                unset($boxList[$idx]);
            } else if (isset($box["boxList"])) {
                $boxList[$idx]["boxList"] = $this->removeBoxByType_r($box["boxList"], $removeTypeList);
            }
        }
        return array_values($boxList);
    }
    function appendICCProfile($iccpData) {
        $context = [];
        $this->boxTree = $this->appendICCProfile_r($this->boxTree, $iccpData, $context);
    }
    function appendICCProfile_r($boxList, $iccpData, &$context) {
        foreach ($boxList as $idx => &$box) {
            switch ($box["type"]) {
            case "ipco":
                $colr = ["type" => "colr",
                         "subtype" => "prof", "data" => $iccpData];
                $box["boxList"][] = $colr;
                $box["boxList"] = array_values($box["boxList"]);
                $context["ipco"] = $box;
                break;
            case "ipma":
                $colrIndex = count($context["ipco"]["boxList"]); // 1 origin
                foreach ($box["entryArray"] as &$entry) {
                    $entry["associationArray"][] = [
                        "essential" => 1, "propertyIndex" => $colrIndex
                    ];
                }
                break;
            default:
                if (isset($box["boxList"])) {
                    $boxList[$idx]["boxList"] = $this->appendICCProfile_r($box["boxList"], $iccpData, $context);
                }
            }
        }
        return $boxList;
    }

    function build($opts = array()) {
        // for iloc => mdat linkage
        $this->ilocBaseOffsetFieldList = []; // _mdatId, _offsetRelative, fieldOffset, fieldSize
        $this->mdatOffsetList = []; // _mdatId, _offset
        //

        $bit = new IO_Bit();
        $this->buildBoxList($bit, $this->boxTree, null, $opts);
        //
        foreach ($this->ilocBaseOffsetFieldList as $ilocBOField) {
            $_mdatId = $ilocBOField["_mdatId"];
            foreach ($this->mdatOffsetList as $mdatOffset) {
                if ($_mdatId === $mdatOffset["_mdatId"]) {
                    $_offsetRelative = $ilocBOField["_offsetRelative"];
                    $fieldOffset = $ilocBOField["fieldOffset"];
                    $baseOffsetSize = $ilocBOField["baseOffsetSize"];
                    $newOffset = $mdatOffset["_offset"] + $_offsetRelative;
                    // XXXn
                    switch ($baseOffsetSize) {
                    case 1:
                        $bit->setUI8($newOffset, $fieldOffset);
                        break;
                    case 2:
                        $bit->setUI16BE($newOffset, $fieldOffset);
                        break;
                    case 4:
                        $bit->setUI32BE($newOffset, $fieldOffset);
                        break;
                    default:
                        new Exception("baseOffsetSize:$baseOffsetSize not implement yet.");
                    }
                    break;
                }
            }
        }
        return $bit->output();
    }
    function buildBoxList($bit, $boxList, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        foreach ($boxList as $box) {
            $this->buildBox($bit, $box, $parentType, $opts);
        }
        list($nextOffset, $dummy) = $bit->getOffset();
        return $nextOffset - $boxOffset;
    }
    function buildBox($bit, $box, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        $bit->putUI32BE(0); // length field.
        $type = $box["type"];
        $bit->putData($type);
        //
        $origOffset = isset($box["_offset"])?$box["_offset"]:null;
        $origLength = isset($box["_length"])?$box["_length"]:null;
        $origDataOffset = $origOffset + 8;
        $origDataLength = $origLength - 8;
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: buildBox: type:$type boxOffset:$boxOffset origOffset:$origOffset origLength:$origLength\n");
        }
        if (isset($box["boxList"])) {
            /*
             * container box
             */
            switch ($type) {
            case "iref":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $dataLength = 4;
                break;
            case "iinf":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                // $count = $box["count"];
                $count = count($box["boxList"]);
                if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                    $bit->putUI16BE($count);
                    $dataLength = 6;
                } else {
                    $bit->putUI32BE($count);
                    $dataLength = 8;
                }
                break;
            case "dref":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $bit->putUI32BE(count($box["boxList"]));
                $dataLength = 8;
                break;
            case "moov": // Movie Atoms
            case "trak":
            case "mdia":
            case "meta": // Metadata
            case "dinf": // data infomation
            case "iprp": // item properties
            case "ipco": // item property container
                if ($type === "meta") {
                    $bit->putUI8($box["version"]);
                    $bit->putUIBits($box["flags"] , 8 * 3);
                    $dataLength = 4;
                } else {
                    $dataLength = 0;
                }
                break;
            default:
                throw new Exception("buildBox: with BoxList type:$type not implemented yet. (boxOffset:$boxOffset)");
                break;
            }
            $dataLength += $this->buildBoxList($bit, $box["boxList"], $type, $opts);
        } else {
            /*
             * no container box (leaf node)
             */
            switch ($type) {
            case "ftyp":
                $bit->putData($box["major"], 4);
                if (! isset($box["minor"])) {
                    $box["minor"] = 0;
                }
                $bit->putUI32BE($box["minor"]);
                foreach ($box["alt"]  as $altData) {
                    $bit->putData($altData, 4);
                }
                break;
            case "hdlr":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putData($box["componentType"] , 4);
                $bit->putData($box["componentSubType"], 4);
                $bit->putData($box["componentManufacturer"], 4);
                $bit->putUI32BE($box["componentFlags"]);
                $bit->putUI32BE($box["componentFlagsMask"]);
                $bit->putData($box["componentName"]);
                break;
            case "iloc":
                if ($parentType === "iref") {
                    $bit->putUI16BE($box["itemID"]);
                    $itemCount = count($box["itemArray"]);
                    $bit->putUI16BE($itemCount);
                    foreach ($box["itemArray"] as $item) {
                        $bit->putUI16BE(item["itemID"]);
                    }
                } else {
                    $bit->putUI8($box["version"]);
                    $bit->putUIBits($box["flags"], 8 * 3);
                    $offsetSize = $box["offsetSize"];
                    $lengthSize = $box["lengthSize"];
                    $bit->putUIBits($offsetSize, 4);
                    $bit->putUIBits($lengthSize, 4);
                    $baseOffsetSize = $box["baseOffsetSize"]; // XXX
                    $bit->putUIBits($baseOffsetSize, 4);
                    if ($box["version"] === 0) {
                        if (isset($box["reserved"])) {
                            $bit->putUIBits($box["reserved"], 4);
                        } else {
                            $bit->putUIBits(0, 4);
                        }
                    } else {
                        $indexSize = $box["indexSize"];
                        $bit->putUIBits($indexSize, 4);
                    }
                    $itemCount = count($box["itemArray"]);
                    $bit->putUI16BE($itemCount);
                    foreach ($box["itemArray"] as $item) {
                        $bit->putUI16BE($item["itemID"]);
                        if ($box["version"] >= 1) {
                            $bit->putUI16BE($item["constructionMethod"]);
                        }
                        $bit->putUI16BE($item["dataReferenceIndex"]);
                        list($fieldOffset, $dummy) = $bit->getOffset();
                        $bit->putUIBits($item["baseOffset"], 8 * $baseOffsetSize);
                        $extentCount = count($item["extentArray"]);
                        $bit->putUI16BE($extentCount);
                        foreach ($item["extentArray"] as $extent) {
                            $bit->putUIBits($extent["extentOffset"], 8 * $offsetSize);
                            if ($box["version"] >= 1) {
                                $bit->putUIBits($extent["extentIndex"], 8 * $indexSize);
                            }
                            $bit->putUIBits($extent["extentLength"] , 8 * $lengthSize);
                        }
                        if (isset($item["_mdatId"])) {
                            $this->ilocBaseOffsetFieldList []= [
                                "_mdatId" => $item["_mdatId"],
                                "_offsetRelative" => $item["_offsetRelative"],
                                "fieldOffset" => $fieldOffset,
                                "baseOffsetSize" => $baseOffsetSize,
                            ];
                        }
                    }
                }
                break;
            case "infe":
                 $bit->putUI8($box["version"]);
                 $bit->putUIBits($box["flags"], 8 * 3);
                 $bit->putUI16BE($box["itemID"]);
                 $bit->putUI16BE($box["itemProtectionIndex"]);
                 if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                     ;
                 } else {
                     $bit->putData($box["itemType"], 4);
                 }
                 $itemName = explode("\0", $box["itemName"])[0];
                 $bit->putData($itemName."\0");
                 if (isset($box["contentType"])) {
                     $contentType = explode("\0", $box["contentType"] )[0];
                     $bit->putData($contentType."\0");
                     if (isset($box["contentEncoding"])) {
                         $contentEncoding = explode("\0", $box["contentEncoding"] )[0];
                         $bit->putData($contentEncoding."\0");
                     }
                 }
                break;
            case "thmb":
            case "cdsc":
            case "dimg":
            case "auxl":
                $bit->putUI16BE($box["fromItemID"]);
                $box["itemCount"] = count($box["itemArray"]);
                $itemCount = count($box["itemArray"]);
                if ($box["itemCount"] !== $itemCount) {
                    throw new Exception("buildBox: box[itemCount]:{$box['itemCount']} != itemCount:$itemCount");
                }
                $box["itemCount"] = $itemCount;
                $bit->putUI16BE($itemCount);
                foreach ($box["itemArray"] as $item) {
                    $bit->putUI16BE($item["itemID"]);
                }
            break;
            case "url ":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putData($box["location"]);
                break;
            case "ispe":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI32BE($box["width"]);
                $bit->putUI32BE($box["height"]);
                break;
            case "pasp":
                $bit->putUI32BE($box["hspace"]);
                $bit->putUI32BE($box["vspace"]);
                break;
            case "pitm":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI16BE($box["itemID"]);
                break;
            case "hvcC":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["profileSpace"], 2);
                $bit->putUIBit($box["tierFlag"]);
                $bit->putUIBits($box["profileIdc"], 5);
                //
                $bit->putUI32BE($box["profileCompatibilityFlags"]);
                $bit->putUIBits($box["constraintIndicatorFlags"], 48);
                //
                $bit->putUI8($box["levelIdc"]);
                //
                $bit->putUIBits(0xF, 4); // reserved
                $bit->putUIBits($box["minSpatialSegmentationIdc"], 12);
                //
                $bit->putUIBits(0x3F, 6); // reserved
                $bit->putUIBits($box["parallelismType"], 2);
                //
                $bit->putUIBits(0x3F, 6); // reserved
                $bit->putUIBits($box["chromaFormat"], 2);
                //
                $bit->putUIBits(0x1F, 5); // reserved
                $bit->putUIBits($box["bitDepthLumaMinus8"], 3);
                //
                $bit->putUIBits(0x1F, 5); // reserved
                $bit->putUIBits($box["bitDepthChromaMinus8"], 3);
                //
                $bit->putUIBits($box["avgFrameRate"], 16);
                //
                $bit->putUIBits($box["constantFrameRate"], 2);
                $bit->putUIBits($box["numTemporalLayers"], 3);
                $bit->putUIBit($box["temporalIdNested"]);
                $bit->putUIBits($box["lengthSizeMinusOne"], 2);
                //
                $bit->putUI8(count($box["nalArrays"]));
                foreach ($box["nalArrays"] as $nal) {
                    $bit->putUIBit($nal["array_completeness"]);
                    $bit->putUIBit(0); // reserved
                    $bit->putUIBits($nal["NALUnitType"], 6);

                    $bit->putUI16BE(count($nal["nalus"]));
                    foreach ($nal["nalus"] as $nalu) {
                        $nalUnitLength = strlen($nalu["nalUnit"]);
                        $bit->putUI16BE($nalUnitLength);
                        $bit->putData($nalu["nalUnit"], $nalUnitLength);
                    }
                }
                break;
            case "colr":
                $bit->putData($box["subtype"], 4);
                $bit->putData($box["data"]);
                break;
            case "pixi":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $channelArray = $box["channelArray"];
                $channelCount = count($channelArray);
                $box["channelCount"] = $channelCount;
                $bit->putUI8($channelCount);
                for ($i = 0 ; $i < $channelCount ; $i++) {
                    $bit->putUI8($channelArray[$i]["bitsPerChannel"]);
                }
            break;
            case "clap":
                $bit->putSI32BE($box["width_N"]);
                $bit->putSI32BE($box["width_D"]);
                $bit->putSI32BE($box["height_N"]);
                $bit->putSI32BE($box["height_D"]);
                $bit->putSI32BE($box["horizOff_N"]);
                $bit->putSI32BE($box["horizOff_D"]);
                $bit->putSI32BE($box["vertOff_N"]);
                $bit->putSI32BE($box["vertOff_D"]);
                break;
            case "irot":
                $bit->putUIBits(0, 6); // reserved
                $bit->putUIBits($box["angle"], 2);
                break;
            case "ipma":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI32BE(count($box["entryArray"]));
                foreach ($box["entryArray"] as $entry) {
                    $bit->putUI16BE($entry["itemID"]);
                    $bit->putUI8(count($entry["associationArray"]));
                    foreach ($entry["associationArray"] as $association) {
                        $bit->putUIBit($association["essential"]);
                        if ($box["flags"] & 1) {
                            $bit->putUIBits($association["propertyIndex"], 15);
                        }  else {
                            $bit->putUIBits($association["propertyIndex"], 7);
                        }
                    }
                }
                break;
            case "mdat":
                if (isset($box["_mdatId"])) {
                    $this->mdatOffsetList []= [
                        "_mdatId" => $box["_mdatId"],
                        "_offset" => $boxOffset,
                    ];
                } else {
                    fwrite(STDERR, "ERROR mdat no _mdatId".PHP_EOL);
                }
                if (isset($box["data"])) {
                    $data = $box["data"];
                } else {
                    $data = substr($this->_isobmffData,
                                   $origDataOffset, $origDataLength);
                }
                $bit->putData($data);
                break;
            default:
                $data = substr($this->_isobmffData, $origDataOffset, $origDataLength);
                $bit->putData($data);
                break;
            }
            list($currentOffset, $dummy) = $bit->getOffset();
            $dataLength = $currentOffset - ($boxOffset + 8);
        }
        $boxLength = 8 + $dataLength;
        $bit->setUI32BE($boxLength, $boxOffset);
    }
    function getBoxesByTypes($types) {
        $params = ['types' => $types, 'boxes' => []];
        $this->applyFunctionToBoxTree($this->boxTree, function($box, &$params) {
            if (in_array($box["type"], $params["types"])) {
                $params["boxes"] []= $box;
            }
        }, $params);
        return $params["boxes"];
    }

    // null $itemID get all item boxes.
    static function getItemIDs($box) {
        $itemIDs = [];
        assert(isset($box["type"]));
        switch ($box["type"]) {
        case "infe":
        case "pitm":
            $itemIDs = [$box["itemID"]];
            break;
        case "thmb":
        case "cdsc":
        case "auxl":
            $itemIDs = [$box["fromItemID"]];
            break;
        case "dimg":
            $itemIDs = [];
            foreach ($box["itemArray"] as $itemEntry) {
                $itemIDs []= $itemEntry["itemID"];
            }
            break;
        }
        return $itemIDs;
    }
    var $cacheItemBoxesByItemIDTable = null;
    function cacheItemBoxesByItemID() {
        if (! is_null($this->cacheItemBoxesByItemIDTable)) {
            return ;
        }
        $itemBoxTable = [];
        $this->applyFunctionToBoxTree($this->boxTree, function($box, &$itemBoxTable) {
            $itemIDs = self::getItemIDs($box);
            foreach ($itemIDs as $itemID) {
                if (! isset($itemBoxTable[$itemID]))  {
                    $itemBoxTable[$itemID] = [];
                }
                $itemBoxTable[$itemID] []= $box;
            }
        }, $itemBoxTable);
        $this->cacheItemBoxesByItemIDTable = $itemBoxTable;
    }
    function getItemBoxesByItemID($itemID = null) {
        $this->cacheItemBoxesByItemID();
        $itemBoxes = null;
        if (is_null($itemID)) {
            $itemBoxes = [];
            $this->applyFunctionToBoxTree($this->boxTree, function($box, &$itemBoxes) {
                $itemIDs = self::getItemIDs($box);
                if (count($itemIDs)) {
                    $itemBoxes []= $box;
                }
            }, $itemBoxes);
        } else {
            if (isset($this->cacheItemBoxesByItemIDTable[$itemID])) {
                $itemBoxes = $this->cacheItemBoxesByItemIDTable[$itemID];
            }
        }
        return $itemBoxes;
    }
    function getPropBoxesByItemID($itemID = null) {
        $propBoxes = [];
        $ipmaBoxes = $this->getBoxesByTypes(["ipma"]);
        if (count($ipmaBoxes) != 1)  {
            $ipmaBoxesCount = count($ipmaBoxes);
            throw new Exception("getPropBoxesByPropIndices: count(ipmaBoxes) must be 1, but $ipmaBoxesCount");
        }
        $ipmaBox = $ipmaBoxes[0];
        if (is_null($itemID)) {
            $propBoxes = getPropBoxesByPropIndex(null);
        } else {
            foreach ($ipmaBox["entryArray"] as $entry) {
                if ($itemID == $entry["itemID"]) {
                    foreach ($entry["associationArray"] as $assoc) {
                        $index = $assoc["propertyIndex"];
                        $boxes = $this->getPropBoxesByPropIndex($index);
                        $propBoxes []= $boxes[0];
                    }
                }
            }
        }
        return $propBoxes;
    }
    var $cachePropBoxByPropIndexTable = null;
    function cachePropBoxByPropIndex() {
        if (! is_null($this->cachePropBoxByPropIndexTable)) {
            return ;
        }
        $ipcoBoxes = $this->getBoxesByTypes(["ipco"]);
        if (count($ipcoBoxes) != 1)  {
            $ipcoBoxCount = count($ipcoBoxes);
            throw new Exception("getPropBoxesByPropIndices: count(ipcoBoxes) must be 1, but $ipcoBoxCount");
        }
        $propBoxTable = [ [] ]; // for 1 origin. set empty as 0 index.
        foreach ($ipcoBoxes[0]["boxList"] as $propBox) {
            $propBoxTable [] = $propBox;
        }
        $this->cachePropBoxByPropIndexTable = $propBoxTable;
    }
    function getPropBoxesByPropIndex($propIndex = null) {
        $this->cachePropBoxByPropIndex();
        if (is_null($propIndex)) {
            return $this->cachePropBoxByPropIndexTable;
        }
        return [$this->cachePropBoxByPropIndexTable[$propIndex]];
    }
    function analyzeProps($boxList) {
        $ipcoBox = $this->getBoxesByTypes(["ipco"]);
        $propTree = [];
        foreach ($ipcoBox[0]["boxList"] as $i => $box) {
            $type = $box["type"];
            $index = $i + 1; // 1 origin
            $prop = [];
            switch ($type) {
            case "irot":
                $prop = ["angle" => $box["angle"]];
                break;
            case "colr":
                $subtype = $box["subtype"];
                $prop = ["subtype" => $subtype];
                if ($subtype === "prof") {
                    $data = $box["data"];
                    $icc = new IO_ICC();
                    try {
                        $icc->parse($data);
                        $desc = null;
                        foreach ($icc->_tags as $tag) {
                            if ($tag->signature === "desc") {
                                if ($tag->parseTagContent()) {
                                    if ($tag->tag->ascii) {
                                        $desc = $tag->tag->ascii;
                                        break;
                                    } else if ($tag->tag->records &&
                                               $tag->tag->records["String"]) {
                                        $desc = $tag->tag->records["String"];
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $desc = "illegal icc profile";
                    }
                    $prop["prof"] = $desc;
                }
                break;
            case "hvcC":
                $prop = ["profile" => $box["profileIdc"],
                         "level" => $box["levelIdc"],
                         "chroma" => $box["chromaFormat"],
                         "compatibility" => $box["profileCompatibilityFlags"],
                         "constraint" => $box["constraintIndicatorFlags"],
                         "bitdepthLuma" => $box["bitDepthLumaMinus8"]+8,
                         "bitdepthChroma" => $box["bitDepthChromaMinus8"]+8,
                         "nalArrays" => $box["nalArrays"]
                ];
                break;
            case "ispe":
                $prop = ["width" => $box["width"],
                         "height" => $box["height"]];
                break;
            case "pixi":
                $channels = $box["channelArray"];
                $bitsPerChannels = [];
                foreach ($channels as $channel) {
                    $bitsPerChannels []= $channel["bitsPerChannel"];
                }
                $prop = ["channels" => $bitsPerChannels];
                break;
            case "auxC":
                $prop = ["auxType" => $box["auxType"],
                         "auxSubType" => $box["auxSubType"]];
                break;
            }
            $propTree[$index][$type] = $prop;
        }
        //
        $this->propTree = $propTree;
    }

    function analyzeItems($boxList) {
        $itemTree = [];
        //
        $infeBoxes = $this->getBoxesByTypes(["infe"]);
        foreach ($infeBoxes as $box) {
            $itemID  = $box["itemID"];
            $itemTree[$itemID] = [];
            $itemTree[$itemID]["infe"] = ["type" => $box["itemType"]];
        }
        $roleBoxes = $this->getBoxesByTypes(["pitm", "thmb", "dimg", "cdsc", "auxl"]);
        foreach ($roleBoxes as $box) {
            $type = $box["type"];
            switch ($type) {
            case "pitm":
                $itemID = $box["itemID"];
                $itemTree[$itemID][$type] = [];
                break;
            case "thmb":
            case "cdsc":
            case "auxl":
                $fromItemID = $box["fromItemID"];
                foreach ($box["itemArray"] as $item) {
                    $itemID = $item["itemID"];
                    $itemTree[$fromItemID][$type] = ["from" => $itemID];
                }
                break;
            case "dimg":
                $fromItemID= $box["fromItemID"];
                foreach ($box["itemArray"] as $item) {
                    $itemID = $item["itemID"];
                    $itemTree[$itemID][$type] = ["from" => $fromItemID];
                }
                break;
            }
        }
        $ilocBoxes = $this->getBoxesByTypes(["iloc"]);
        foreach ($ilocBoxes as $ilocBox) {
            foreach ($ilocBox['itemArray'] as $item) {
                $itemID = $item["itemID"];
                $method = isset($item["constructionMethod"])?$item["constructionMethod"]:null;
                $reference = $item["dataReferenceIndex"];
                $offset = $item["baseOffset"];
                foreach ($item["extentArray"] as $extent) {
                    if (($offset === 0) && ($extent["extentOffset"])) {
                        $offset = $extent["extentOffset"];
                    }
                    $length = $extent["extentLength"];
                }
                $itemTree[$itemID]["iloc"] = ["reference" => $reference,
                                              "offset"    => $offset,
                                              "length"    => $length];
                if (! is_null($method)) {
                    $itemTree[$itemID]["iloc"]["method"] = $method;
                }
            }
        }
        $ipmaBoxes = $this->getBoxesByTypes(["ipma"]);
        foreach ($ipmaBoxes as $box) {
            foreach ($box['entryArray'] as $entry) {
                $itemID = $entry["itemID"];
                $propIndices = [];
                foreach ($entry['associationArray'] as $assoc) {
                    $propIndices []= ["essential" => $assoc["essential"],
                                      "index" => $assoc["propertyIndex"]];
                }
                $itemTree[$itemID]["ipma"] = $propIndices;
            }
        }
        //
        $this->itemTree = $itemTree;
    }
    function analyze() {
        $this->analyzeProps($this->boxTree);
        $this->analyzeItems($this->boxTree);
    }
    function tree($opts = array()) {
        if (is_null($this->propTree) || is_null($this->itemTree)) {
            $this->analyze();
        }
        echo "Props:".PHP_EOL;
        foreach ($this->propTree as $index => $prop) {
            $types = array_keys($prop);
            assert (count($types) === 1);
            $type = $types[0];
            echo "[$index]: ".$type;
            switch ($type) {
            case "colr":
                $subtype = $prop[$type]["subtype"];
                echo " subtype:".$subtype;
                if ($subtype === "prof") {
                    echo " prof:".$prop[$type]["prof"];
                }
                break;
            case "hvcC":
                $profile = $prop[$type]["profile"];
                $level = $prop[$type]["level"];
                $chroma = $prop[$type]["chroma"];
                echo " profile:".$profile."(".getProfileIdcDescription($profile).") level:".$level." chroma:".$chroma."(".getChromeFormatDescription($chroma).")";
                break;
            case "pixi":
                echo " channels:".implode(",", $prop[$type]["channels"]);
                break;
            case "irot":
                echo " angle:".$prop[$type]["angle"];
                break;
            case "ispe":
                echo " width:".$prop[$type]["width"]." height:".$prop[$type]["height"];
                break;
            case "auxC":
                echo " auxtype:".$prop[$type]["auxType"];
                break;
            }
            echo PHP_EOL;
            if ($type == "hvcC") {
                $hvcC = $prop["hvcC"];
                // var_dump($hvcC);
                printf("    compatibility:0x%08x constraint:0x%012x bitdepth,%d:%d",
                       $hvcC["compatibility"], $hvcC["constraint"],
                       $hvcC["bitdepthLuma"], $hvcC["bitdepthChroma"]);
                echo PHP_EOL;
                foreach ($hvcC["nalArrays"] as $nals) {
                    // var_dump($nals);
                    $naltype = $nals["NALUnitType"];
                    echo "    naltype:$naltype ";
                    foreach ($nals["nalus"] as $nalu) {
                        $len = $nalu["nalUnitLength"];
                        echo "(len:$len) ";
                        self::hexDump($nalu["nalUnit"], 0, $len, 0x10);
                        echo PHP_EOL;
                    }
                }
            }
        }
        echo "Items:".PHP_EOL;
        foreach ($this->itemTree as $itemID => $item) {
            echo "[$itemID]:";
            foreach (["pitm", "dimg", "thmb", "cdsc", "auxl"] as $type) {
                if (isset($item[$type])) {
                    echo " ".$type;
                    if (isset($item[$type]["from"])) {
                        echo ":".$item[$type]["from"];
                    }
                }
            }
            if (isset($item["infe"]["type"])) {
                echo " type:".$item["infe"]["type"];
            } else {
                echo " type: (infe type empty)";
            }
            if (isset($item["iloc"])) {
                $iloc = $item["iloc"];
                if (isset($iloc["method"])) {
                    echo " method:".$iloc["method"];
                }
                echo " ref:".$iloc["reference"]." offset:".$iloc["offset"]." length:".$iloc["length"];
            }
            echo PHP_EOL;
            if (isset($item["iloc"])) {
                $iloc = $item["iloc"];
                $method = 0;
                if (isset($iloc["method"])) {
                    $method = $iloc["method"];
                }
                if ($method == 0) {
                    $offset = $iloc["offset"];
                    $length = $iloc["length"];
                    printf("    (mdat:0x%x,0x%x) ", $offset,$length);
                    $this->mdatHexDump($offset, $length, 0x10);
                } else if ($method == 1) {
                    $idatBoxes = $this->getBoxesByTypes(["idat"]);
                    if (count($idatBoxes) != 1) {
                        echo "(count(idatBoxes) != 1)";
                    }
                    $idat = $idatBoxes[0];
                    $offset = $idat["_offset"];
                    $length = $idat["_length"];
                    printf("    (idat:0x%x,0x%x) ", $offset,$length);
                    $this->idatHexDump($offset, $length, 0x10);
                } else {
                    echo "(unknown iloc method)";
                }
            }
            echo PHP_EOL;
            if (isset($item["ipma"])) {
                $propIndices = $item["ipma"];
                echo "    ";
                foreach ($propIndices as $index) {
                    $i = $index["index"];
                    echo "[".$i;
                    echo $index["essential"]?"":"?";
                    echo "]";
                    $prop = $this->propTree[$i];
                    $types = array_keys($prop);
                    assert (count($types) === 1);
                    $type = $types[0];
                    echo $type;
                    switch ($type) {
                    case "irot":
                        echo ":".$prop[$type]["angle"];
                        break;
                    case "ispe":
                        echo ":".$prop[$type]["width"].",".$prop[$type]["height"];
                        break;
                    case "colr":
                        echo ":".$prop[$type]["subtype"];
                        break;
                    case "hvcC":
                        echo ":".$prop[$type]["profile"].",".$prop[$type]["level"].",".$prop[$type]["chroma"];
                        break;
                    case "pixi":
                        echo ":".implode(",", $prop[$type]["channels"]);
                        break;
                    }
                    echo " ";
                }
                echo PHP_EOL;
            }
        }
    }
    function mdatHexDump($offset, $length, $maxLength) {
        self::hexDump($this->_isobmffData, $offset, $length, $maxLength);
    }
    function idatHexDump($offset, $length, $maxLength) {
        $this->mdatHexDump($offset, $length, $maxLength);
    }
    static function hexDump($data, $offset, $length, $maxLength) {
        $n = ($length <= $maxLength)?$length:$maxLength;
        for ($i = 0 ; $i < $n ; $i++) {
            printf("%02X ", ord($data[$offset + $i]));
        }
        if ($n < $length) {
            printf("...");
        }
    }

}
