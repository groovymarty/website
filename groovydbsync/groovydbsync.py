import heapq
import sys
import os
import shutil
import time, datetime

from dropbox.client import DropboxClient
 
token = "Qy-em1wdEgUAAAAAAAHVjPV6XQp5uWgcUO3cCICyU8x3lRxd2YcV6cxIvtlsq6Q7"

basedir = "/home/groovymarty/Dropbox"

cursorfile = "/home/groovymarty/dbox_cursor"

delete_ok = True

cursor = None
if os.path.isfile(cursorfile):
  with open(cursorfile, 'r') as f:
    cursor = f.read()

lowercase_dir_to_real_dir = {"/": basedir}

def scan_dir(relpath):
  abspath = os.path.join(basedir, relpath)
  for item in os.listdir(abspath):
    absitem = os.path.join(abspath, item)
    if os.path.isdir(absitem):
      relitem = os.path.join(relpath, item)
      relitemlow = relitem.lower() 
      lowercase_dir_to_real_dir["/"+relitemlow] = os.path.join(basedir, relitem)
      scan_dir(relitem)

print("starting groovydbsync", datetime.datetime.now())

scan_dir("")
#for key in sorted(lowercase_dir_to_real_dir.keys()):
#  print(key, "->", lowercase_dir_to_real_dir[key])
#exit(0)

def list_files(client, cursor=None):
  has_more = True
  reset = False
 
  while has_more:
    result = client.delta(cursor)
    cursor = result['cursor']
    has_more = result['has_more']
    if result['reset']:
      reset = True
 
    for lowercase_path, metadata in result['entries']:

      # Sometimes last element of path is not lowercase, so force it
      lowercase_path = lowercase_path.lower()
      lowercase_dir, lowercase_file = os.path.split(lowercase_path)
      # Skip iTunes
      if lowercase_dir.startswith('/itunes') or lowercase_dir.startswith('/st lukes'):
        print("Skipping ", lowercase_path)
        continue
      if lowercase_dir not in lowercase_dir_to_real_dir:
        # This means Dropbox thinks we should have a directory but we don't have it
        print("dir not found:", lowercase_dir, "for file", lowercase_file, "meta" if metadata is not None else "no meta")
        if metadata is not None:
          print("Try again after deleting cursor file to force reset")
          exit()
        else:
          print("Ignore and keep going because file was to be deleted anyway")
          continue
      real_dir = lowercase_dir_to_real_dir[lowercase_dir]

      if metadata is not None:
        filename = os.path.basename(metadata['path'])
        real_path = os.path.join(real_dir, filename)
        if os.path.isfile(real_path):
          if metadata['is_dir']:
            print("local file but dbox is_dir", real_path)
            exit()
          # Existing file, if reseting then check metadata otherwise always download
          download(client, real_path, metadata, reset)
        elif os.path.isdir(real_path):
          if not metadata['is_dir']:
            print("local dir but not dbox is_dir", real_path)
            exit()
        else:
          #print("****NOT FOUND: ", real_path)
          if metadata['is_dir']:
            print("making directory", real_path)
            os.mkdir(real_path)
            lowercase_dir_to_real_dir[lowercase_path] = real_path
          else:
            # New file, always download
            download(client, real_path, metadata, False)
      else:
        # no metadata indicates a deletion
        # all we have is lowercase name so we must search for match
        for item in os.listdir(real_dir):
          if item.lower() == lowercase_file:
            real_path = os.path.join(real_dir, item)
            if os.path.isfile(real_path):
              print("remove file", real_path)
              if delete_ok:
                os.remove(real_path)
            elif os.path.isdir(real_path):
              print("remove dir", real_path)
              if delete_ok:
                shutil.rmtree(real_path)
            else:
              print("*** NOT FOUND TO REMOVE:", real_path)

  return cursor
 
def download(client, realpath, metadata, check_metadata):
  fmt = "%a, %d %b %Y %H:%M:%S +0000"
  if check_metadata:
    mtime = os.path.getmtime(realpath)
    mtime_dt = datetime.datetime(*time.gmtime(mtime)[:6])
    mtime_str = mtime_dt.strftime(fmt)
    size = os.path.getsize(realpath)
    if mtime_str == metadata['client_mtime'] and size == metadata['bytes']:
      #print("skipping", realpath)
      return
    print(mtime_str, metadata['client_mtime'], size, metadata['bytes'])
  print("downloading", realpath)
  with open(realpath, 'wb') as flocal:
    with client.get_file(metadata['path']) as fremote:
      while True:
        buf = fremote.read(4096*1024)
        if not buf:
          break
        flocal.write(buf)
  print(metadata['client_mtime'])
  mtime_dt = datetime.datetime.strptime(metadata['client_mtime'], fmt)
  mtime = mtime_dt.replace(tzinfo=datetime.timezone.utc).timestamp()
  os.utime(realpath, (mtime, mtime))

cursor = list_files(DropboxClient(token), cursor)

with open(cursorfile, 'w') as f:
  f.write(cursor)

print("groovydbsync complete", datetime.datetime.now())
