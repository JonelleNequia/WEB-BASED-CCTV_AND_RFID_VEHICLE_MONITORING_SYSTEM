<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Unprivileged intents only. No process, network, trust or service control. */
class UpdateRequests
{
    public function status(): array
    {
        $base = config('lifecycle.root');
        $path = $base ? dirname($base).'/update-status.json' : null;
        if (! $path || ! is_file($path)) return ['schema'=>1,'provisioned'=>false,'last_check'=>['outcome'=>'not provisioned'],'candidate'=>null];
        abort_if(filesize($path)>2097152, 503, 'Update status is unavailable.');
        $value = json_decode(file_get_contents($path), true);
        abort_unless(is_array($value) && ($value['schema'] ?? null) === 1, 503, 'Update status is unavailable.');
        return $value + ['provisioned'=>true];
    }

    public function directory(): string { return storage_path('app/update-inbox'); }

    public function enqueue(Request $request, string $action, array $inputs): string
    {
        abort_unless($this->status()['provisioned'], 422, 'An OS administrator must provision publisher trust and the retained controller first.');
        File::ensureDirectoryExists($this->directory());
        $lock=fopen($this->directory().'/.queue.lock','c');abort_unless($lock!==false,503,'Update request storage is unavailable.');
        try {
            abort_unless(flock($lock,LOCK_EX),503,'Update request storage is busy.');
            $directories=File::directories($this->directory());
            abort_if(count(array_filter($directories,fn ($p)=>!is_file($p.'/processed.json')))>=20,429,'Update request storage is full. Ask the administrator to review pending requests.');
            if ($action==='upload') {
                $slots=array_filter($directories,function ($path) {
                    $intent=is_file($path.'/request.json') ? json_decode(File::get($path.'/request.json'),true) : [];
                    return is_file($path.'/package.bin') || (($intent['action'] ?? null)==='upload' && !is_file($path.'/processed.json'));
                });
                abort_if(count($slots)>=2,429,'Two imports are already reserved or retained. Ask the OS administrator to review them first.');
            }
            $id=(string) Str::uuid();$path=$this->directory().'/'.$id;File::makeDirectory($path,0700);
            $this->saveIntent($request,$id,$action,$inputs);return $id;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    public function saveIntent(Request $request, string $id, string $action, array $inputs): void
    {
        $data = ['schema'=>1,'id'=>$id,'actor'=>(int)$request->user()->id,'session_version'=>(int)$request->user()->session_version,
            'created_at'=>now()->toIso8601String(),'action'=>$action,'inputs'=>$inputs];
        File::put($this->directory().'/'.$id.'/request.json', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
