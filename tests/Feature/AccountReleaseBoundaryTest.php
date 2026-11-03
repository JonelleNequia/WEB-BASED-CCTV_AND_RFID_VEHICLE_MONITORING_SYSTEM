<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountReleaseBoundaryTest extends TestCase
{
    public function test_source_archive_excludes_installation_data_and_keeps_empty_session_directory(): void
    {
        // Inspect filenames only; never read database/credential contents.
        $path = tempnam(sys_get_temp_dir(), 'philcst-export-');
        try {
            (new Process(['git', 'archive', '--worktree-attributes', '--format=tar', '--output='.$path, 'HEAD'], base_path()))->mustRun();
            $list = (new Process(['tar', '-tf', $path], base_path()))->mustRun()->getOutput();
            $entries = array_filter(explode("\n", $list), fn ($entry) => $entry !== '' && ! str_ends_with($entry, '/'));
            $unsafe = array_filter($entries, fn ($entry) => preg_match('/\.(sqlite(-wal|-shm)?|db|sql)$/', $entry)
                || (str_starts_with($entry, '.env') && $entry !== '.env.example')
                || (str_starts_with($entry, 'storage/') && basename($entry) !== '.gitignore')
                || str_starts_with($entry, 'tests/') || str_starts_with($entry, 'database/factories/')
                || in_array(basename($entry), ['first-admin.txt', 'install-result.txt'], true));
            $this->assertCount(0, $unsafe, 'Release source must contain no installation data or account fixtures.');
            $this->assertContains('storage/framework/sessions/.gitignore', $entries);
            $this->assertContains('storage/logs/.gitignore', $entries);
            $script = File::get(base_path('build/windows/build-release.ps1'));
            $this->assertStringContainsString('Release blocked: installation-specific data', $script);
            $this->assertStringContainsString('Bundled PHP must support Argon2id', $script);
        } finally {
            File::delete($path);
        }
    }
}
