# Bitcoin Seed Tool

Single-page seed tool, built with upstream's `npm run build` (`node build.js`)
into one self-contained `dist/index.html` and served by the `bundler-spa`
platform's nginx. The build reproduces the committed release file byte for
byte. Everything runs in the visitor's browser; the server keeps no data.
