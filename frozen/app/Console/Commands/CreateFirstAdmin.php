<?php

namespace App\Console\Commands;

use App\Services\AccountSecurityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateFirstAdmin extends Command
{
    protected $signature = 'system:first-admin {--email=admin@philcst.local} {--name=Administrator}
        {--initialize : Explicitly authorize setup on a fresh local installation}
        {--replace : Replace an unused setup code}
        {--output-file= : Protected local delivery file outside public/}
        {--json : Print non-secret status only}';

    protected $description = 'Issue a first-admin setup code through a protected local file; never creates a login password';

    public function handle(AccountSecurityService $security): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        Validator::make(compact('email', 'name'), ['email' => 'required|email|max:255', 'name' => 'required|string|max:120'])->validate();
        $path = (string) $this->option('output-file');
        if ($this->option('initialize') || $this->option('replace')) {
            $directory = realpath(dirname($path));
            $public = realpath(public_path());
            if (! $path || ! $directory || ! is_writable($directory) || is_link($path)
                || $directory === $public || str_starts_with($directory.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR)) {
                $this->error('Provide a protected local --output-file outside public/. Restrict its directory before running this command.');

                return self::FAILURE;
            }
            if (is_file($path) && ! $this->option('replace')) {
                $this->error('Delivery file already exists. Keep the current code, or explicitly use --replace for interrupted setup.');

                return self::FAILURE;
            }
        }
        $code = $security->issueSetup($email, $name, (bool) $this->option('initialize'), (bool) $this->option('replace'));
        if ($code !== null) {
            $handle = @fopen($path, $this->option('replace') ? 'wb' : 'x');
            if ($handle === false) {
                $this->error('Delivery failed. Use --replace with a protected writable delivery file to retry.');

                return self::FAILURE;
            }
            @chmod($path, 0600);
            $delivery = "PHILCST first-admin setup\nOpen /setup (never put the code in a URL).\nCode: ".$code."\nExpires in 24 hours; 5 attempts. Deliver privately, then delete this file.\n";
            $written = fwrite($handle, $delivery);
            fclose($handle);
            if ($written !== strlen($delivery)) {
                $this->error('Delivery incomplete. Explicitly replace the code through the protected local workflow.');

                return self::FAILURE;
            }
        }
        $result = ['issued' => $code !== null];
        $this->line($this->option('json') ? json_encode($result) : ($result['issued']
            ? 'Setup code written to the protected delivery file. No password has been created.'
            : 'No code issued: setup is completed, awaiting its current code, or not explicitly initialized.'));

        return self::SUCCESS;
    }
}
