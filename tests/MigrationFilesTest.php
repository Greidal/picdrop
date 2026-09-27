<?php

use PHPUnit\Framework\TestCase;

/** Static checks of db/migrations; the migrations themselves run against MariaDB in CI. */
final class MigrationFilesTest extends TestCase
{
    public function testMigrationFilesAreConsistent(): void
    {
        $files = array_merge(
            glob(picdropMigrationsDir() . '/*.sql') ?: [],
            glob(picdropMigrationsDir() . '/*.php') ?: []
        );
        $this->assertNotEmpty($files);

        $versions = [];
        foreach ($files as $file) {
            $name = basename($file);
            $this->assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.(sql|php)$/', $name);
            $versions[] = (int) $name;

            if (str_ends_with($name, '.php')) {
                $this->assertIsCallable(require $file, "$name must return a callable");
            }
        }

        sort($versions);
        $this->assertSame(range(1, count($versions)), $versions, 'versions must be unique and without gaps');
    }
}
