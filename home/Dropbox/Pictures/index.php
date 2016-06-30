<?php
$baseDir = '/home/groovymarty/Dropbox/Pictures';
$baseUrl = 'http://pictures.groovymarty.com';
$cacheDir = '/home/groovymarty/Pictures_cache';
$useImagick = true;

// if no file or id specified, do photo browser
$id = "";
if (array_key_exists('id', $_GET)) {
  $id = $_GET['id'];
  // parse id, for example "D13P2-001-edited" gives:
  // $idParts[1] = "D13P", the parent directory
  // $idParts[2] = "2", the child directory (or empty string if none)
  // $idParts[3] = "1", the photo number sans leading zeros
  // $idParts[4] = "-edited", optional dash ending (or empty string if none)
  // $idParts[5] = "", dot extension if any (unused)
  if (!preg_match('/^([A-Z][A-Z]*[0-9][0-9]*[A-Z]*)([0-9]*)-0*([1-9][0-9]*)([^.]*)(.*)/', $id, $idParts)) {
    error("Invalid id: $id");
  }

  // find parent directory
  $dir = do_glob("Parent directory", $baseDir, $idParts[1]);

  // possible child directory
  if ($idParts[2]) {
    $dir = do_glob("Child directory", $dir, $idParts[1].$idParts[2]);
  }
  if (!is_dir($dir)) {
    error("$dir is not a directory!");
  }

  // use glob to find candidate matches, then compare numbers for exact match
  // if id has a dash ending, like "-edited", it must occcur as substring in file name
  // if multiple candidates pass these tests, keep the one with the shortest name
  // suppose we have these files:
  // 1) D13X-001.jpg
  // 2) D13X-001-xmas-2013-edited.jpg
  // 3) D13X-001-xmas-2013-edited-again.jpg
  // 4) D13X-010.jpg
  // 5) D13X-100.jpg
  // here are examples showing how we want the matching to work:
  // id="D13X-1", glob returns all 5 files, files 1,2,3 match the number, pick 1 because it's shortest
  // id="D13X-1-ed", glob returns files 2 and 3, pick 2 because it's shortest
  // id="D13X-10", glob returns files 4 and 5, file 4 matches the number
  $fixedPart = $dir.'/'.$idParts[1].$idParts[2].'-';
  $fixedPartLen = strlen($fixedPart);
  $globPat = $fixedPart.'*'.$idParts[3].'*'.$idParts[4].'*';
  $found = "";
  foreach (glob($globPat) as $match) {
    $wildPart= substr($match, $fixedPartLen);
    if (preg_match("/^0*([1-9][0-9]*).*/", $wildPart, $num) && $num[1] == $idParts[3]) {
      if (!$found || strlen($match) < strlen($found)) {
        $found = $match;
      }
    }
  }
  if (!$found) {
    error("Sorry, picture $id not found");
  }
  $picPath = $found;
} elseif (array_key_exists('f', $_GET)) {
  $picPath = $baseDir.'/'.$_GET['f'];
  if (!file_exists($picPath)) {
    error("Sorry, file $f does not exist");
  }
} else {
  photo_browser();
  exit();
}

if ($useImagick) {
  $im = new Imagick($picPath);
  $w = $im->getImageWidth();
  $h = $im->getImageHeight();
} else {
  $info = getimagesize($picPath);
  $w = $info[0];
  $h = $info[1];
}

// possible resize
// if "s" specified, resize image so largest side = s
// if "w" specified, resize image so width = w
$width = "";
if (array_key_exists('s', $_GET)) {
  $side = $_GET['s'];
  if ($w >= $h) {
    // landscape orientation, set width = side
    $width = $side;
    $height = round(($h * $side) / $w);
  } else {
    // portrait orientation, set height = side 
    $width = round(($w * $side) / $h);
    $height = $side;
  }
} elseif (array_key_exists('w', $_GET)) {
  $width = $_GET['w'];
  $height = round(($h * $width) / $w);
}
if ($width) {
  // see if resized image is in cache
  // cache filename is relative path to desired picture with underscores for slashes
  // and width inserted
  $cacheFile = insert_width(strtr(substr($picPath, strlen($baseDir)+1), '/', '_'), $width);
  $cachePath = $cacheDir.'/'.$cacheFile;
  if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($picPath)) {
    // touch file for LRU algorithm
    touch($cachePath);
  } else {
    // resize the image and add to cache
    if ($useImagick) {
      //$im->thumbnailImage($width, $height);
      $orientation = $im->getImageOrientation();
      $im->resizeImage($width, $height, Imagick::FILTER_TRIANGLE, 1);
      switch($orientation) {
        case Imagick::ORIENTATION_BOTTOMRIGHT:
          $im->rotateImage("#000", 180); // rotate 180 degrees
          break;
        case Imagick::ORIENTATION_RIGHTTOP:
          $im->rotateImage("#000", 90); // rotate 90 degrees CW
          break;
        case Imagick::ORIENTATION_LEFTBOTTOM:
          $im->rotateImage("#000", -90); // rotate 90 degrees CCW
          break;
      }
      $im->setImageCompression(Imagick::COMPRESSION_JPEG);
      $im->setImageCompressionQuality(75);
      $im->stripImage();
      //$im->setImageOrientation($orient);
      $im->writeImage($cachePath);
    } else {
      $orig = imagecreatefromjpeg($picPath);
      $scal = imagescale($orig, $width);
      imagejpeg($scal, $cachePath);
      imagedestroy($orig);
      imagedestroy($scal);
    }
  }
  $resultPath = $cachePath;
} else {
  // no resize
  $resultPath = $picPath;
}

// generate headers
header("Content-Type: image/jpeg");
if (array_key_exists('dl', $_GET)) {
  // download option
  $pathParts = explode("/", $picPath);
  $fileName = array_pop($pathParts);
  if ($width) {
    // we are resizing, so insert width into file name
    $fileName = insert_width($fileName, $width);
  }
  header("Content-Disposition: attachment; filename=\"$fileName\"");
}
header("Content-Length: ".filesize($resultPath));

// emit the image
readfile($resultPath);
if ($useImagick) {
  $im->clear();
}
exit();
  
function do_glob($what, $path, $prefix) {
  $sought = $path.'/'.$prefix;
  $globPat = $sought.'*';
  $found = "";
  foreach (glob($globPat) as $match) {
    $tailSep = substr($match, strlen($sought), 1);
    if ($tailSep == "" || strpos(" -", $tailSep) !== false) {
      if ($found) {
        error("$what multiple matches<br>\nglob pattern=$globPat");
      }
      $found = $match;
    }
  }
  if (!$found) {
    error("$what not found<br>\nglob pattern=$globPat");
  }
  return $found;
}
 
function error($msg) {
  global $id;
  echo "<html><body>\n";
  echo "$msg<br>\n";
  echo "id=$id<br>\n";
  echo "</body></html>\n";
  exit();
}

function insert_width($fileName, $width) {
  $idot = strrpos($fileName, '.');
  return substr($fileName, 0, $idot).'_w'.$width.substr($fileName, $idot);
}

function photo_browser() {
  global $baseDir, $baseUrl; ?>
<html>
<head>
<meta id="meta" name="viewport" content="width=device-width; initial-scale=1.0" />
<style>
body {width: 100%;}
h1 {font-size: 18pt; display: inline;}
h2 {font-size: 16pt;}
div.picture {padding: 5px 0px 5px 0px;}
span.bigbold {font-size: 16pt; font-weight: bold;}
</style>
</head>
<body>
<?php
  echo "<h1>Welcome to <a href=\"$baseUrl\">".substr($baseUrl,7)."</a>!</h1>\n";
  $dir = "";
  $pat = "";
  $curPath = $baseDir;
  $parent = "";
  $dirParam = "";
  if (array_key_exists('dir', $_GET)) {
    $dir = $_GET['dir'];
    $dirParts = explode("/", $dir);
    $title = array_pop($dirParts);
    if (count($dirParts)) {
      print_back("?dir=".urlencode(implode("/", $dirParts)));
    } else {
      if (preg_match("/^(D[0-9][0-9]*).*/", $dir, $parts)) {
        print_back("?pat=".$parts[1]);
      } else {
        print_back("?pat=".substr($dir, 0, 1));
      }
    }
    echo "<h2>".htmlentities($title)."</h2>\n";
    $curPath .= '/'.$dir;
    $parent = urlencode($dir).'/';
    $dirParam = '&amp;dir='.urlencode($dir);
  } elseif (array_key_exists('pat', $_GET)) {
    $pat = $_GET['pat'];
    echo "<!--pat is $pat-->\n";
    if (strlen($pat) > 1) {
      print_back("?pat=".substr($pat, 0, 1));
    } else {
      print_back($baseUrl);
    }
    echo "<p>";
  } else {
    echo "<p>";
  }
  $brPending = false;
  $files = array();
  $lastOne = "";
  foreach (scandir($curPath) as $d) {
    $let = substr($d, 0, 1);
    if (!ctype_alpha($let)) continue;
    if (is_dir($curPath.'/'.$d)) {
      if ($pat && substr($d, 0, strlen($pat)) != $pat) continue;
      if ($pat || $dir) {
        if ($pat == "D" && preg_match("/^(D[0-9][0-9]*).*/", $d, $parts)) {
          $dpat = $parts[1];
          if ($dpat != $lastOne) {
            echo "<a href=\"?pat=$dpat$dirParam\">$dpat</a>&nbsp; \n";
            $brPending = true;
            $lastOne = $dpat;
          }
        } else {
          if ($brPending) {
            echo "<br><br>\n";
            $brPending = false;
          }
          echo "<a href=\"?dir=$parent".urlencode($d)."\">$d</a><br>\n";
        }
      } else {
        // main directory listing by letters
        if ($let != $lastOne) {
          echo "<a href=\"?pat=$let$dirParam\">$let</a>&nbsp; \n";
          $brPending = true;
          $lastOne = $let;
        }
      }
    } else {
      if (strtolower(substr($d, -4)) == ".jpg") {
        $files[] = $d;
      }
    }
  }
  if ($brPending) {
    echo "<br><br>\n";
    $brPending = false;
  }
  $n = count($files);
  $page = 1;
  if (array_key_exists('page', $_GET)) {
    $page = $_GET['page'];
  }
  $nPerPage = 40;
  $nPages = ceil($n / $nPerPage);
  if ($nPages > 1) {
    $params = "";
    if ($dir) {
      $params .= "&amp;dir=".urlencode($dir);
    }
    if ($pat) {
      $params .= "&amp;pat=$pat";
    }
    for ($p = 1; $p <= $nPages; $p++) {
      echo "<a href=\"?page=$p$params\">";
      if ($p == $page) {
        echo "<span class=\"bigbold\">$p</span>";
      } else {
        echo $p;
      }
      echo "</a>&nbsp; \n";
    }
    echo "<br><br>\n";
  }
  $i = ($page - 1) * $nPerPage;
  $iEnd = min($i + $nPerPage, $n);
  for (; $i < $iEnd; $i++) {
    $f = $files[$i];
    if (preg_match("/^([ADEFS][0-9][0-9]*[A-Z]*[0-9]*-[0-9][0-9]*[^\/]*)\.[^.]*/", $f, $parts)) {
      $ref = "id=".urlencode($parts[1]);
      $name = $parts[1];
    } else {
      $relPath = substr($curPath, strlen($baseDir)+1).'/'.$f;
      $ref = "f=".urlencode($relPath);
      $name = $f;
    }
    echo "<div class=\"picture\">\n";
    echo "<a href=\"?$ref&amp;s=650\"><img src=\"?$ref&amp;s=250\"></a><br>\n";
    echo "<a href=\"?$ref\">$name</a></div>\n";
  }
  if ($page < $nPages) {
    echo "<br><a href=\"?page=", $page+1, "$params\">MORE</a>\n";
  }
  echo "</body></html>\n";
}

function print_back($url) {
  echo "&nbsp; <a href=\"$url\">BACK</a>\n";
}
