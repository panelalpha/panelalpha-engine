<?php
# PanelAlpha front controller for PmWiki. PmWiki is reached as pmwiki.php?n=...;
# it ships no index.php, so this is the shim its own install docs prescribe
# (pmwiki.org/PmWiki/Installation). With this present, docroot "." serves the
# root and PhpDocroot resolves it.
include_once('pmwiki.php');
