# Upstream's pytition/settings/config_example.py, filled from the account.
import os
from pytition.settings.base import *

SECRET_KEY = os.environ['PYTITION_SECRET_KEY']
STATIC_ROOT = '/static/'
STATIC_URL = '/static/'
MEDIA_ROOT = '/mediaroot/'
MEDIA_URL = '/mediaroot/'
DATABASES = {
    'default': {
        'ENGINE': 'django.db.backends.postgresql',
        'NAME': 'pytition',
        'USER': 'pytition',
        'PASSWORD': os.environ['PYTITION_DB_PASSWORD'],
        'HOST': 'db',
        'PORT': 5432,
    }
}
ALLOWED_HOSTS = [os.environ['PA_PUBLIC_HOST'], '127.0.0.1', 'localhost', '[::1]']
# TLS ends at the engine's proxy; forms are posted from the https origin.
CSRF_TRUSTED_ORIGINS = [os.environ['PA_PUBLIC_URL']]

# Unchanged from config_example.py ("DO NOT EDIT AFTER THIS BANNER").
if USE_MAIL_QUEUE:
    INSTALLED_APPS += ('mailer',)
    EMAIL_BACKEND = "mailer.backend.DbBackend"

if os.environ.get('DEBUG'):
    DEBUG = True
