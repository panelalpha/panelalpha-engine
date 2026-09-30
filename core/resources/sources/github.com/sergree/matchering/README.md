# Matchering (github.com/sergree/matchering)

Audio matching and mastering. The repository is the Python library; its
DOCKER.md points self-hosters at the Matchering WEB image
(`sergree/matchering-web`, source github.com/sergree/matchering-web), which
runs Django, an RQ worker and Redis under supervisord on port 8360.

The recipe runs `sergree/matchering-web:0.1.7` (current release; `latest` is
the same digest) with `/app/data` on the named volume `mgw-data`. Uploaded and
mastered files are deleted by the app after 60 minutes. The app has no
accounts; every visitor can upload a target and a reference track.
