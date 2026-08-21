<?php

declare(strict_types=1);

/**
 * Pest bootstrap.
 *
 * Defines the ABSPATH constant every plugin file guards on, then loads the
 * WordPress test doubles. The doubles live in one place (Stubs/) so every
 * test shares the same fake world: an in-memory postmeta store, canned
 * upload directories, and predictable UUIDs.
 */

define( 'ABSPATH', sys_get_temp_dir() . '/contentcopy-tests/' );

require __DIR__ . '/Stubs/wordpress.php';
