<html>
<head>
<meta id="meta" name="viewport" content="width=device-width; initial-scale=1.0" />
<style>
body {width: 100%;}
h1 {font-size: 18pt;}
h2 {font-size: 16pt;}
</style>
</head>
<body>
<?php
$safeCmds = array('dbsyncStatus', 'dbsyncLog', 'gitStatus', 'resizeStatus');
if (!in_array($_POST['cmd'], $safeCmds) && $_POST['verify'] != "ytram") {
  $title = "Error";
  $output = array("Verification failed.");
} else {
  switch ($_POST['cmd']) {
    case 'startDbsync':
      $title="Start Dropbox Sync";
      exec("/home/groovymarty/bin/startDbsync", $output, $retval);
      break;
    case 'stopDbsync':
      $title="Stop Dropbox Sync";
      exec("/home/groovymarty/bin/stopDbsync", $output, $retval);
      break;
    case 'dbsyncStatus':
      $title="Dropbox Sync Status";
      exec("/home/groovymarty/bin/dbsyncStatus", $output, $retval);
      break;
    case 'dbsyncLog':
      $title="Dropbox Sync Log";
      $output = file("/home/groovymarty/dbsync.log");
      $retval = 0;
      break;
    case 'delCursor':
      $title="Delete Dropbox Cursor";
      exec("rm /home/groovymarty/dbox_cursor", $output, $retval);
      break;
    case 'syncAll':
      $title="Sync All";
      exec("/home/groovymarty/bin/syncAll", $output, $retval);
      break;
    case 'gitStatus':
      $title="Git Status";
      exec("cd /home/groovymarty/website; git status", $output, $retval);
      break;
    case 'gitAdd':
      $title="Git Add";
      exec("cd /home/groovymarty/website; git add -A", $output, $retval);
      break;
    case 'gitCommit':
      $title="Git Commit";
      exec("cd /home/groovymarty/website; git commit -F /home/groovymarty/.gitmessage", $output, $retval);
      file_put_contents("/home/groovymarty/.gitmessage", "Development");
      break;
    case 'gitPush':
      $title="Git Push";
      exec("cd /home/groovymarty/website; git push", $output, $retval);
      break;
    case 'gitPull':
      $title="Git Pull";
      exec("cd /home/groovymarty/website; git pull", $output, $retval);
      break;
    case 'startResize':
      $title="Start Resize";
      exec("/home/groovymarty/bin/startResize", $output, $retval);
      break;
    case 'stopResize':
      $title="Stop Resize";
      exec("/home/groovymarty/bin/stopResize", $output, $retval);
      break;
    case 'resizeStatus':
      $title="Resize Status";
      exec("/home/groovymarty/bin/resizeStatus", $output, $retval);
      break;
    default:
      $title="Error";
      $output=array("No such command: ".$_POST['cmd']);
  }
}
echo "<h1>Welcome to <a href=\"/\">admin.groovymarty.com</a></h1>\n";
echo "<h2>$title</h2>\n";
foreach ($output as $line) {
  echo htmlspecialchars($line), "<br>\n";
}
if ($retval) {
  echo "Returned: $retval\n";
} else {
  echo "Done\n";
}
if ($_POST['cmd'] == 'dropboxStatus') { ?>
<p>
<form method="post">
<input type="hidden" name="cmd" value="dropboxStatus">
<input type="submit" value="Again">
</form>
<?php } ?>
</body>
</html>
