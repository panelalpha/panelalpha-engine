<?php
/*
 * Written by PanelAlpha. Not upstream's file, and deliberately empty.
 *
 * go\core\Environment::sourceIsEncoded() (go/core/Environment.php:175-186)
 * decides whether this installation's source is ionCube/SourceGuardian encoded
 * by reading the first 200 bytes of this exact path and looking for `sg_load`.
 * It is a file of the paid edition, and .gitignore excludes /www/go/modules/*
 * except community/ -- so in a git checkout it is missing, file_get_contents
 * raises E_WARNING, Group Office's own error handler turns that into an
 * ErrorException, and Installer::install() dies in registerCoreEntities().
 *
 * An empty file is the honest answer to the question being asked: this source
 * is not encoded, so hasIoncube() returns true and ClassFinder::canBeDecoded()
 * stops refusing every class it scans. It defines no class, so class_exists()
 * is false and ClassFinder skips it exactly as it would have skipped a file
 * that was never there.
 */
