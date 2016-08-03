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
  if (!preg_match('/^([A-Z][A-Z]*[0-9]*[A-Z]*)([0-9]*)-0*([1-9][0-9]*)([^.]*)(.*)/', $id, $idParts)) {
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
} elseif (array_key_exists('req', $_GET)) {
  // do a requested image resize
  $req = $_GET['req'];
  $params = array();
  $relPath = parse_cache_name($req, $params);
  $picPath = $baseDir.'/'.$relPath;
  if (file_exists($picPath)) {
    process_image_request($picPath, $params, false);
    exit();
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
  error(array(
    count_files($cacheDir)." images in cache",
    count_files($requestDir)." requests pending"));
} else {
  photo_browser();
  exit();
}

process_image_request($picPath, $_GET);
exit();

function process_image_request($picPath, $params, $emitImage=true) {
  global $baseDir, $cacheDir, $requestDir, $useImagick;
  // possible resize
  // if "s" specified, resize image so largest side = s
  // if "w" specified, resize image so width = w
  // if "h" specified, resize image so height = h
  $side = $width = $height = 0;
  if (array_key_exists('s', $params)) {
    $side = $params['s'];
  } elseif (array_key_exists('w', $params)) {
    $width = $params['w'];
  } elseif (array_key_exists('h', $params)) {
    $height = $params['h'];
  }
  if ($side || $width || $height) {
    // see if resized image is in cache
    // cache filename is relative path to desired picture with underscores for slashes
    // and resize parameters appended
    $relPath = substr($picPath, strlen($baseDir)+1);
    $cacheFile = gen_cache_name($relPath, $params);
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
      // not in cache so must resize now
      // get original image dimensions
      if ($useImagick) {
        $im = new Imagick($picPath);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
      } else {
        $info = getimagesize($picPath);
        $w = $info[0];
        $h = $info[1];
      }
      if (!$w || !$h) {
        error("Image dimension is zero");
      }
      // compute new image dimensions
      if ($side) {
        if ($w >= $h) {
          // landscape orientation, set width = side
          $width = $side;
          $height = round(($h * $side) / $w);
        } else {
          // portrait orientation, set height = side 
          $width = round(($w * $side) / $h);
          $height = $side;
        }
      } elseif ($width) {
        // width is given so compute height
        $height = round(($h * $width) / $w);
      } else {
        // height is given so compute width
        $width = round(($w * $height) / $h);
      }

      // resize the image and add to cache
      if ($useImagick) {
        // apply exif orientation because we're going to strip metadata
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
        $im->writeImage($cachePath);
        $im->clear();
      } else {
        $orig = imagecreatefromjpeg($picPath);
        $scal = imagescale($orig, $width);
        imagejpeg($scal, $cachePath);
        imagedestroy($orig);
        imagedestroy($scal);
      }
    }
    // delete request file if any
    if (file_exists($requestPath)) {
      unlink($requestPath);
    }
    $resultPath = $cachePath;
  } else {
    // no resize
    $resultPath = $picPath;
  }

  if ($emitImage) {
    // emit headers
    header("Content-Type: image/jpeg");
    header("Cache-Control: public; max-age=2592000"); //30 days
    header("Expires: ".gmdate('D, d M Y H:i:s \G\M\T', time()+2592000));

    // possible download option
    if (array_key_exists('dl', $params)) {
      $fileName = insert_resize_params(basename($picPath), $params);
      header("Content-Disposition: attachment; filename=\"$fileName\"");
    }
    header("Content-Length: ".filesize($resultPath));

    // emit the image
    readfile($resultPath);
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
function gen_cache_name($relPath, $params) {
  $str = strtr(str_replace('_', '__', $relPath), '/', '_');
  return insert_resize_params($str, $params);
}

function insert_resize_params($fileName, $params) {
  $idot = strrpos($fileName, '.');
  if ($idot === false) {
    $idot = strlen($fileName);
  }
  $str = substr($fileName, 0, $idot);
  $ext = substr($fileName, $idot);

  foreach (array('w', 'h', 's') as $key) {
    if (array_key_exists($key, $params)) {
      $str .= "_".$key."=".$params[$key];
    }
  }
  return $str.$ext;
}

// reverse what the above functions do
function parse_cache_name($cacheName, &$params) {
  $idot = strrpos($cacheName, '.');
  if ($idot === false) {
    $idot = strlen($cacheName);
  }
  $str = substr($cacheName, 0, $idot);
  $ext = substr($cacheName, $idot);
  
  while (preg_match("/(.*)_([a-z][a-z]*)=([0-9][0-9]*)\$/", $str, $parts)) {
    $str = $parts[1];
    $params[$parts[2]] = intval($parts[3]);
  }
  return strtr(str_replace('__', '/', $str), '/_', '_/').$ext;
}

function test_cache($cachePath, $picPath) {
  return file_exists($cachePath) && filemtime($cachePath) >= filemtime($picPath);
}

function count_files($dir) {
  $n = 0;
  foreach (scandir($dir) as $d) {
    if (!is_dir($dir.'/'.$d)) $n++;
  }
  return $n;
}

function photo_browser() {
  global $baseDir, $baseUrl, $inBody, $cacheDir, $requestDir;
  // part=0 means generate entire page
  // part=1 means generate only the list of pictures (during javascript refresh)
  // view means full-screen display at specified array index
  $part = 0;
  if (array_key_exists('part', $_GET)) {
    $part = $_GET['part'];
  }
  $view = false;
  if (array_key_exists('view', $_GET)) {
    $view = true;
    $iView = intval($_GET['view']);
  }
  if (!$part) { ?>
<html>
<head>
<meta id="meta" name="viewport" content="width=device-width; initial-scale=1.0" />
<style>
body {width: 100%;<?php if ($view) echo "background: black;"; ?>}
h1 {font-size: 18pt; display: inline;}
h2 {font-size: 16pt;}
div.piclistitem {padding: 5px 0px 5px 0px;}
img.view {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    right: 0;
    max-width: 100%;
    max-height: 100%;
    margin: auto;
    overflow: auto;
}
span.bigbold {font-size: 16pt; font-weight: bold;}
</style>
</head>
<body>
<?php
  }
  if (!$view) {
    echo "<h1>Welcome to <a href=\"$baseUrl\">".substr($baseUrl,7)."</a>!</h1>\n";
  }
  $inBody = true;
  $dir = "";
  $pat = "";
  $curPath = $baseDir;
  $parent = "";
  $dirParam = "";
  if (array_key_exists('dirid', $_GET)) {
    $dirid = $_GET['dirid'];
    if (!preg_match('/^([A-Z][A-Z]*[0-9]*[A-Z]*)([0-9]*).*/', $dirid, $idParts)) {
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
    if (!$part && !$view) {
      if (count($dirParts)) {
        print_up("/?".gen_dir_param(implode("/", $dirParts)));
      } else if (preg_match("/^(D[0-9][0-9]*).*/", $dir, $parts)) {
        print_up("/?pat=".$parts[1]);
      } else {
        print_up("/?pat=".substr($dir, 0, 1));
      }
      echo "<h2>".htmlentities($title)."</h2>\n";
    }
    $parent = urlencode($dir).'/';
    $dirParam = '&amp;dir='.urlencode($dir);
  } elseif (array_key_exists('pat', $_GET)) {
    $pat = $_GET['pat'];
    if (!$part && !$view) {
      if (strlen($pat) > 1) {
        print_up("/?pat=".substr($pat, 0, 1));
      } else {
        print_up("/");
      }
      echo "<p>";
    }
  } elseif (!$part && !$view) {
    echo "<p>";
  }
  $brPending = false;
  $files = array();
  $lastOne = "";
  foreach (scandir($curPath) as $d) {
    $let = substr($d, 0, 1);
    if (!ctype_alpha($let)) continue;
    if (is_dir($curPath.'/'.$d)) {
      if ($part || $view) continue;
      if ($pat && substr($d, 0, strlen($pat)) != $pat) continue;
      if (stripos($d, "private")) continue;
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
  # the parameters that got us here
  $params = "";
  if ($dir) {
    $params .= "&amp;".gen_dir_param($dir);
  } elseif ($pat) {
    $params .= "&amp;pat=$pat";
  }
  $nPerPage = 40;
  $nPages = ceil($n / $nPerPage);
  if (!$part && !$view) {
    if ($nPages > 1) {
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
    echo "<div id=\"piclist\">\n";
  }
  if ($view) {
    $i = $iView;
    $iEnd = $iView + 1;
  } else {
    $i = ($page - 1) * $nPerPage;
    $iEnd = min($i + $nPerPage, $n);
  }
  $inNormDir = substr(gen_dir_param($curPath), 0, 5) == "dirid";
  $prep = false;
  for (; $i < $iEnd; $i++) {
    $f = $files[$i];
    $picPath = $curPath.'/'.$f;
    $relPath = substr($curPath, strlen($baseDir)+1).'/'.$f;
    if ($inNormDir && preg_match("/^([A-Z][A-Z]*[0-9]*[A-Z]*[0-9]*-[0-9][0-9]*[^\/]*)\.[^.]*/", $f, $parts)) {
      $ref = "id=".urlencode($parts[1]);
      $name = $parts[1];
    } else {
      $ref = "f=".urlencode($relPath);
      $name = $f;
    }
    
    if ($view) {
      echo "<img class=\"view\" src=\"/?$ref&amp;s=650\">\n";
    } else {
      $resizeParams = array('s' => 250);    
      $cacheFile = gen_cache_name($relPath, $resizeParams);
      $cachePath = $cacheDir.'/'.$cacheFile;
      $requestPath = $requestDir.'/'.$cacheFile;
      if (test_cache($cachePath, $picPath)) {
        // found file in cache
        $thumbSrc = "/?$ref&amp;s=250";
      } else {
        $thumbSrc = "/preparing-image.gif";
        touch($requestPath);
        $prep = true;
      }
      echo "<div class=\"piclistitem\">\n";
      echo "<a href=\"/?view=$i$params\"><img src=\"$thumbSrc\"></a><br>\n";
      echo "<a href=\"/?$ref\">$name</a></div>\n";
    }
  }
  if ($prep) {
    // as long as this element is present, javascript will keep refreshing
    echo "<input id=\"prep\" type=\"hidden\" value=\"1\">\n";
  }
  if (!$part && !$view) {
    echo "</div>\n"; //piclist
    if ($page < $nPages) {
      echo "<br><a href=\"?page=", $page+1, "$params\">MORE</a>\n";
    }
    // If any images are being prepared by background resize process,
    // send the following javascript which refreshes the picture list
    // every 3 seconds until all images are ready.
    // The javascript requests this very same page again, but with the
    // "part" parameter set to 1.  This parameter short-circuits a lot
    // of the above code so only the picture list is generated.
    // The response data from this request replaces everything inside
    // the "piclist" div.  So if there are 40 images in the list, all
    // 40 of them are replaced, even ones that were already complete.
    // However the completed images should be in the browser cache,
    // so the browser should not have to fetch them again.
    // As the resized images become available, the <img> tags will
    // point to them and the browser will fetch them.  When all images
    // are ready, the "prep" hidden field will go away and the javascript
    // activity will stop.  (Note "prep" is inside the "piclist" div
    // so it's replaced every time we refresh.)
    if ($prep) { ?>
<script type="text/javascript">
function refreshPicList() {
  var request = new XMLHttpRequest();
  request.onreadystatechange = function() { 
    if (request.readyState == 4 && request.status == 200) {
      document.getElementById("piclist").innerHTML = request.responseText;
      if (document.getElementById("prep")) {
        window.setTimeout(refreshPicList, 3000);
      }
    }
  }
  request.open("GET", "<?=$_SERVER['REQUEST_URI']?>&part=1", true);
  request.send(null);
}
window.setTimeout(refreshPicList, 3000);
</script>
<?php
    }
  }
  if (!$part) {
    echo "</body>\n</html>\n";
  }
}

function print_up($url) {
  echo "&nbsp; <a href=\"$url\">UP</a>\n";
}

function gen_dir_param($dir) {
  if (preg_match("/^([A-Z][A-Z]*[0-9]*[A-Z]*[0-9]*).*/", basename($dir), $parts)) {
    return "dirid=".$parts[1];
  } else {
    return "dir=".urlencode($dir);
  }
}

