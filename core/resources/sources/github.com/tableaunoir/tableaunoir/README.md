# Tableaunoir

Browser blackboard, built with the project's own `npm run build` (webpack) and
served from `dist/` by nginx. The repo's compose file needs a locally built
`tableaunoir:latest` image, so it is not used. Boards are saved by the visitor
(browser or file); shared boards connect to the websocket server named in
`src/config.json` (upstream's public one). The server keeps no data.
