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
<form action="doCommand.php" method="post">
<h1>Welcome to <a href="/">admin.groovymarty.com</a></h1>
<select name="cmd">
<option value="startDropbox">Start Dropbox</option>
<option value="stopDropbox">Stop Dropbox</option>
<option value="dropboxStatus" selected>Dropbox Status</option>
</select>
<p>Verify: <input name="verify"/>
<input type="submit" value="Submit"></p>
</form>
</body>
</html>
