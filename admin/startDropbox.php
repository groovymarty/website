<html>
<head>
</head>
<body>
<h1>Start Dropbox</h1>
<?php
exec("/home/groovymarty/bin/startDropbox", $output, $retval);
foreach ($output as $line) {
  echo htmlspecialchars($line), "<br>\n";
}
echo "Returned: $retval"; ?>
</body>
</html>
