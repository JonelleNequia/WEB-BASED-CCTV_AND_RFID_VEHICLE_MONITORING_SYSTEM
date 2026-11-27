<?php

namespace App\Services;

use App\Support\OccurrenceMedia;
use Illuminate\Support\Facades\Storage;

/** Clean only this request's newly allocated private files after an ordinary rollback. */
class DeliveryMediaJournal
{
    private bool $active = false;
    private array $paths = [];
    public function begin(): void { $this->active = true; $this->paths = []; }
    public function track(string $path): void { if ($this->active) $this->paths[] = $path; }
    public function finish(): void
    {
        $this->active = false;
        foreach ($this->paths as $path) {
            try {
                if (($file = OccurrenceMedia::privateFile($path)) && ! OccurrenceMedia::referenced($path)) Storage::disk('occurrences')->delete($file);
            } catch (\Throwable $exception) { report($exception); }
        }
        $this->paths = [];
    }
}
