<?php
$baseDir = '/home/groovymarty/Dropbox/Pictures';
$baseUrl = 'http://pictures.groovymarty.com';
$cacheDir = '/home/groovymarty/Pictures_cache';
$requestDir = '/home/groovymarty/Pictures_cache/Requests';
$useImagick = true;
$inBody = false;

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
} elseif (array_key_exists('clearCache', $_GET)) {
  exec("find $cacheDir -type f -delete 2>&1", $output, $retval);
  if ($retval) {
    array_unshift($output, "Failed to clear cache!");
    error($output);
  } else {
    error("Cache cleared.");
  }
} elseif (array_key_exists('cacheStats', $_GET)) {
  system("find $cacheDir -type f | wc -l 2>&1");
  echo " images in cache<br>";
  system("ls $requestDir | wc -l 2>&1");
  echo " requests pending<br>";
  exit();
} else {
  photo_browser();
  exit();
}

process_image_request($picPath, $_GET);
exit();

function process_image_request($picPath, $params) {
  global $baseDir, $cacheDir, $requestDir, $useImagick;
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
  // if "h" specified, resize image so height = h
  $resizeParam = "";
  if (array_key_exists('s', $params)) {
    $side = $params['s'];
    if ($w >= $h) {
      // landscape orientation, set width = side
      $width = $side;
      $height = round(($h * $side) / $w);
    } else {
      // portrait orientation, set height = side 
      $width = round(($w * $side) / $h);
      $height = $side;
    }
    $resizeParam = "s=$side";
  } elseif (array_key_exists('w', $params)) {
    $width = $params['w'];
    $height = round(($h * $width) / $w);
    $resizeParam = "w=$width";
  } elseif (array_key_exists('h', $params)) {
    $height = $params['h'];
    $width = round(($w * $height) / $h);
    $resizeParam = "h=$height";
  }
  if ($resizeParam) {
    // see if resized image is in cache
    // cache filename is relative path to desired picture with underscores for slashes
    // and width inserted
    $relPath = substr($picPath, strlen($baseDir)+1);
    $cacheFile = gen_cache_name($relPath, $resizeParam);
    $cachePath = $cacheDir.'/'.$cacheFile;
    $requestPath = $requestDir.'/'.$cacheFile;
    if (test_cache($cachePath, $picPath)) {
      // found file in cache, touch file for LRU algorithm
      touch($cachePath);
    } elseif (array_key_exists('nowait', $params)) {
      // not in cache and don't want to wait
      // add request for background process, redirect to "Preparing Image..."
      touch($requestPath);
      header("Location: /preparing-image.gif");
      exit();
    } else {
      // resize the image and add to cache
      if ($useImagick) {
        $orientation = $im->getImageOrientation();
        //$im->thumbnailImage($width, $height);
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
        $im->writeImage($cachePath);
      } else {
        $orig = imagecreatefromjpeg($picPath);
        $scal = imagescale($orig, $width);
        imagejpeg($scal, $cachePath);
        imagedestroy($orig);
        imagedestroy($scal);
      }
      // delete request file if any
      if (file_exists($requestPath)) {
        unlink($requestPath);
      }
    }
    $resultPath = $cachePath;
  } else {
    // no resize
    $resultPath = $picPath;
  }

  // generate headers
  header("Content-Type: image/jpeg");
  header("Cache-Control: public; max-age=31536000"); //1 year
  header("Expires: ".gmdate('D, d M Y H:i:s \G\M\T', time()+31536000));

  if (array_key_exists('dl', $params)) {
    // download option
    $pathParts = explode("/", $picPath);
    $fileName = array_pop($pathParts);
    if ($resizeParam) {
      // we are resizing, so insert width into file name
      $fileName = insert_resize_param($fileName, $resizeParam);
    }
    header("Content-Disposition: attachment; filename=\"$fileName\"");
  }
  header("Content-Length: ".filesize($resultPath));

  // emit the image
  readfile($resultPath);
  if ($useImagick) {
    $im->clear();
  }
}
  
function do_glob($what, $path, $prefix) {
  $sought = $path.'/'.$prefix;
  $globPat = $sought.'*';
  $found = "";
  foreach (glob($globPat) as $match) {
    $tailSep = substr($match, strlen($sought), 1);
    if ($tailSep == "" || strpos(" -", $tailSep) !== false) {
      if ($found) {
        error(array("$what multiple matches", "glob pattern=$globPat"));
      }
      $found = $match;
    }
  }
  if (!$found) {
    error(array("$what not found", "glob pattern=$globPat"));
  }
  return $found;
}
 
function error($msgs) {
  global $id, $inBody;
  if (!$inBody) {
    echo "<html><body>\n";
  } else {
    echo "<p>\n";
  }
  if (!is_array($msgs)) {
    $msgs = array($msgs);
  }
  foreach ($msgs as $msg) {
    echo htmlentities($msg)."<br>\n";
  }
  if ($id) {
    echo "id=".htmlentities($id)."<br>\n";
  }
  echo "</body></html>\n";
  exit();
}

// generate cache file name for given relative path
// replace all underscores with double underscores
// replace all slashes with single underscores
// append resize param (w=, h= or s=)
function gen_cache_name($relPath, $resizeParam) {
  $str = strtr(str_replace('_', '__', $relPath), '/', '_');
  return insert_resize_param($str, $resizeParam);
}

function insert_resize_param($fileName, $resizeParam) {
  $idot = strrpos($fileName, '.');
  if ($idot === false) {
    $idot = strlen($fileName);
  }
  return substr($fileName, 0, $idot).'_'.$resizeParam.substr($fileName, $idot);
}

// reverse what the above functions do
function parse_cache_name($cacheName) {
}

function test_cache($cachePath, $picPath) {
  return file_exists($cachePath) && filemtime($cachePath) >= filemtime($picPath);
}

function photo_browser() {
  global $baseDir, $baseUrl, $inBody, $cacheDir, $requestDir; ?>
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
  $inBody = true;
  echo "<h1>Welcome to <a href=\"$baseUrl\">".substr($baseUrl,7)."</a>!</h1>\n";
  $dir = "";
  $pat = "";
  $curPath = $baseDir;
  $parent = "";
  $dirParam = "";
  if (array_key_exists('dirid', $_GET)) {
    $dirid = $_GET['dirid'];
    if (!preg_match('/^([A-Z][A-Z]*[0-9][0-9]*[A-Z]*)([0-9]*).*/', $dirid, $idParts)) {
      error("Invalid directory id: $dirid");
    }

    // find parent directory
    $curPath = do_glob("Parent directory", $baseDir, $idParts[1]);

    // possible child directory
    if ($idParts[2]) {
      $curPath = do_glob("Child directory", $curPath, $idParts[1].$idParts[2]);
    }
    $dir = substr($curPath, strlen($baseDir)+1);
  }
  elseif (array_key_exists('dir', $_GET)) {
    $dir = $_GET['dir'];
    $curPath .= '/'.$dir;
  }
  if ($dir) {
    if (!is_dir($curPath)) {
      error("Directory not found: $dir");
    }
    $dirParts = explode("/", $dir);
    $title = array_pop($dirParts);
    if (count($dirParts)) {
      print_up("/?".gen_dir_param(implode("/", $dirParts)));
    } else if (preg_match("/^(D[0-9][0-9]*).*/", $dir, $parts)) {
      print_up("/?pat=".$parts[1]);
    } else {
      print_up("/?pat=".substr($dir, 0, 1));
    }
    echo "<h2>".htmlentities($title)."</h2>\n";
    $parent = urlencode($dir).'/';
    $dirParam = '&amp;dir='.urlencode($dir);
  } elseif (array_key_exists('pat', $_GET)) {
    $pat = $_GET['pat'];
    if (strlen($pat) > 1) {
      print_up("/?pat=".substr($pat, 0, 1));
    } else {
      print_up("/");
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
            echo "<a href=\"/?pat=$dpat$dirParam\">$dpat</a>&nbsp; \n";
            $brPending = true;
            $lastOne = $dpat;
          }
        } else {
          if ($brPending) {
            echo "<br><br>\n";
            $brPending = false;
          }
          echo "<a href=\"/?".gen_dir_param($parent.$d)."\">$d</a><br>\n";
        }
      } else {
        // main directory listing by letters
        if ($let != $lastOne) {
          echo "<a href=\"/?pat=$let$dirParam\">$let</a>&nbsp; \n";
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
      $params .= "&amp;".gen_dir_param($dir);
    }
    elseif ($pat) {
      $params .= "&amp;pat=$pat";
    }
    for ($p = 1; $p <= $nPages; $p++) {
      echo "<a href=\"/?page=$p$params\">";
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
  $inNormDir = substr(gen_dir_param($curPath), 0, 5) == "dirid";
  $misses = 0;
  $prep = false;
  for (; $i < $iEnd; $i++) {
    $f = $files[$i];
    $relPath = substr($curPath, strlen($baseDir)+1).'/'.$f;
    if ($inNormDir && preg_match("/^([ADEFS][0-9][0-9]*[A-Z]*[0-9]*-[0-9][0-9]*[^\/]*)\.[^.]*/", $f, $parts)) {
      $ref = "id=".urlencode($parts[1]);
      $name = $parts[1];
    } else {
      $ref = "f=".urlencode($relPath);
      $name = $f;
    }

    $resizeParam = "s=250";    
    $cacheFile = gen_cache_name($relPath, $resizeParam);
    $cachePath = $cacheDir.'/'.$cacheFile;
    $requestPath = $requestDir.'/'.$cacheFile;
    if (test_cache($cachePath, $picPath) || ++$misses <= 100) { //<---- HERE
      // found file in cache, or first miss
      $thumbSrc = "/?$ref&amp;$resizeParam";
    } else {
      $thumbSrc = "/preparing-image.gif";
      touch($requestPath);
      $prep = true;
    }

    echo "<div class=\"picture\">\n";
    echo "<a href=\"/?$ref&amp;s=650\"><img src=\"$thumbSrc\"></a><br>\n";
    echo "<a href=\"/?$ref\">$name</a></div>\n";
  }
  if ($page < $nPages) {
    echo "<br><a href=\"?page=", $page+1, "$params\">MORE</a>\n";
  }
  if ($prep) { ?>
<script type="text/javascript">
window.setTimeout(function(){ window.location="<?=$_SERVER['REQUEST_URI']?>"; },3000);
</script>
<?php
  }
  echo "</body></html>\n";
}

function print_up($url) {
  echo "&nbsp; <a href=\"$url\">UP</a>\n";
}

function gen_dir_param($dir) {
  if (preg_match("/^([ADEFS][0-9][0-9]*[A-Z]*[0-9]*).*/", basename($dir), $parts)) {
    return "dirid=".$parts[1];
  } else {
    return "dir=".urlencode($dir);
  }
}

