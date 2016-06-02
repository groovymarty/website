<html>
<head>
</head>
<body>
<h1>Dropbox status</h1>
<?php
exec("/home/groovymarty/bin/dropbox.py status", $output, $retval);
foreach ($output as $line) {
  echo htmlspecialchars($line), "<br>\n";
}
echo "Returned: $retval\n"; ?>
</body>
</html>
