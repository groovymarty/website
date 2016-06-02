<html>
<head>
</head>
<body>
<h1>Stop Dropbox</h1>
<?php
exec("/home/groovymarty/bin/stopDropbox", $output, $retval);
foreach ($output as $line) {
  echo htmlspecialchars($line), "<br>\n";
}
echo "Returned: $retval\n"; ?>
</body>
</html>
