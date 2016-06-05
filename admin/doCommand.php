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
if ($_POST['cmd'] != 'dropboxStatus' && $_POST['verify'] != "ytram") {
  $title = "Error";
  $output = array("Verification failed.");
} else {
  switch ($_POST['cmd']) {
    case 'startDropbox':
      $title="Start Dropbox";
      exec("/home/groovymarty/bin/startDropbox", $output, $retval);
      break;
    case 'stopDropbox':
      $title="Stop Dropbox";
      exec("/home/groovymarty/bin/stopDropbox", $output, $retval);
      break;
    case 'dropboxStatus':
      $title="Dropbox Status";
      exec("/home/groovymarty/bin/dropbox.py status", $output, $retval);
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
      break;
    case 'gitPush':
      $title="Git Push";
      exec("cd /home/groovymarty/website; git push", $output, $retval);
      break;
    case 'gitPull':
      $title="Git Pull";
      exec("cd /home/groovymarty/website; git pull", $output, $retval);
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
