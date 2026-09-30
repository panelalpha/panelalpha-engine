# Stremio Web

Built with upstream's own Dockerfile (webpack build, express serving
`build/` on 8080). The only addition is `files/Dockerfile.dockerignore`,
which keeps `.git` in the build context because `webpack.config.js` names
its output after `git rev-parse HEAD`; the engine's generated ignore file
drops `.git` (engine#413). Nothing is stored on the server: addons, library
and account live with the visitor's browser and Stremio's own API.

Give the account about 3 GB (memory_limit 3072): the webpack build ran out
of memory at 2500 and passed at 3072 and 4096.
