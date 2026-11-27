<?php
namespace App\Http\Controllers;
class DeploymentVersionController extends Controller
{
    public function __invoke()
    {
        $file=dirname(base_path()).'/BUILD-INFO.json';
        $info=is_file($file) ? json_decode(file_get_contents($file),true) : [];
        return response()->json(['version'=>$info['version'] ?? null,'source'=>$info['source']['source_sha256'] ?? null])->header('Cache-Control','no-store');
    }
}
