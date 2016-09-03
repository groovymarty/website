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
<option value="startDbsync">Start Dropbox Sync</option>
<option value="stopDbsync">Stop Dropbox Sync</option>
<option value="dbsyncStatus" selected>Dropbox Sync Status</option>
<option value="syncAll">Sync Files</option>
<option value="gitStatus">Git Status</option>
<option value="gitAdd">Git Add</option>
<option value="gitCommit">Git Commit</option>
<option value="gitPush">Git Push</option>
<option value="gitPull">Git Pull</option>
<option value="startResize">Start Resize</option>
<option value="stopResize">Stop Resize</option>
<option value="resizeStatus">Resize Status</option>
</select>
<p>Verify: <input name="verify"/>
<input type="submit" value="Submit"></p>
</form>
</body>
</html>
