#!/bin/sh
# Create the admin once; a redeploy (user exists) leaves it and its password alone.
set -e
cd /yamtrack
: "${YAMTRACK_ADMIN_USER:?admin.env missing; prepare.sh did not run}"
python manage.py shell -c '
import os
from django.contrib.auth import get_user_model
User = get_user_model()
name = os.environ["YAMTRACK_ADMIN_USER"]
if User.objects.filter(username=name).exists():
    print("[panelalpha] admin exists; left unchanged")
else:
    User.objects.create_user(username=name, password=os.environ["YAMTRACK_ADMIN_PASSWORD"], is_staff=True, is_superuser=True)
    print("[panelalpha] admin created")
'
