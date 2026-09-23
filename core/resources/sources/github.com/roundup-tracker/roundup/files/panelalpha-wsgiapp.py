import os

from roundup.cgi.wsgi_handler import RequestDispatcher

# Serve the single tracker at the URL root. cache_tracker keeps the opened
# tracker instance warm between requests (see roundup upgrading docs).
tracker_home = os.environ.get("TRACKER_HOME", "/data/tracker")
app = RequestDispatcher(tracker_home, feature_flags={"cache_tracker": ""})
