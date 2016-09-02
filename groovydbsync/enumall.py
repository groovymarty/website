import heapq
import sys
 
from dropbox.client import DropboxClient
 
if len(sys.argv) == 2:
  token = sys.argv[1]
else:
  print 'Usage: python app.py <access token>'
  sys.exit(1)
 
def list_files(client, files=None, cursor=None):
  if files is None:
    files = {}
 
  has_more = True
 
  while has_more:
    result = client.delta(cursor)
    cursor = result['cursor']
    has_more = result['has_more']
    print "cursor", cursor, "has_more", has_more
 
    for lowercase_path, metadata in result['entries']:

      if metadata is not None:
        files[lowercase_path] = metadata
 
      else:
        # no metadata indicates a deletion
 
        # remove if present
        files.pop(lowercase_path, None)
 
        # in case this was a directory, delete everything under it
        for other in files.keys():
          if other.startswith(lowercase_path + '/'):
            del files[other]
 
  return files, cursor
 
files, cursor = list_files(DropboxClient(token))
 
print 'Total Dropbox size: %d bytes' % sum([metadata['bytes'] for metadata in files.values()])
 
print
 
print 'Top 10 biggest files:'
 
for path, metadata in heapq.nlargest(10, files.items(), key=lambda x: x[1]['bytes']):
  print 't%s: %d bytes' % (path, metadata['bytes'])
