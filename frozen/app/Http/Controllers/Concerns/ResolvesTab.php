<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * UI Phase 2: pick the active ?tab= value, falling back to the first tab.
 */
trait ResolvesTab
{
    /**
     * @param  array<string, string>  $tabs
     */
    protected function resolveTab(Request $request, array $tabs): string
    {
        $tab = (string) $request->query('tab', '');

        return array_key_exists($tab, $tabs) ? $tab : (string) array_key_first($tabs);
    }
}
