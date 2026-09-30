# Netron

Viewer for neural network, deep learning and machine learning models.

- The checkout is not an installable package on its own (upstream's `package.py`
  assembles it), so the recipe runs the released server instead: `pip install
  netron==9.3.0` on python:3.12-slim, `netron --host 0.0.0.0 -p 8080`.
- Models are opened from the browser ("Open Model..."); nothing is stored server-side.
- No login, as upstream ships it.
