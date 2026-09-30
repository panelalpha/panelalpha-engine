# EmulatorJS

Browser-side RetroArch frontend, served as committed with the `static`
platform's nginx. Nothing is built: `npm run build` only packs release
archives with 7z. The visitor picks a ROM in the page and it runs in their
browser; emulator cores are fetched from cdn.emulatorjs.org because the
repository does not commit them. The server keeps no data.
