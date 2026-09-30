# gamja

Web IRC client, built with the project's own `npm run build` (Parcel) and served
from `dist/` by nginx. `npm start` is only a development server and is not used.

gamja needs an IRC WebSocket server, which it does not include. Point it at one
with the URL parameter `?server=wss://irc.example.org` or with a `config.json`
next to `index.html` (see upstream `doc/config-file.md`). Without either it
tries `/socket` on this domain, which is not proxied. No server-side data.
