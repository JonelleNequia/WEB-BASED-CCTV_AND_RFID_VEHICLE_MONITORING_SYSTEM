<?php
namespace App\Http\Controllers;

use App\Services\UpdateRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class UpdateController extends Controller
{
    public function index(Request $request, UpdateRequests $updates)
    {
        abort_unless($request->user()->role === 'admin', 403);
        return view('settings.index', ['tab'=>'updates','updateStatus'=>$updates->status()]);
    }

    public function request(Request $request, UpdateRequests $updates)
    {
        $data=$request->validate(['action'=>['required','in:check,download,preferences,plan,install'],
            'candidate'=>['nullable','regex:/\A[a-f0-9]{64}\z/'],'plan_confirmation'=>['nullable','regex:/\A[a-f0-9]{64}\z/'],'channel'=>['nullable','in:stable,prerelease,development'],
            'automatic_check'=>['nullable','boolean'],'automatic_download'=>['nullable','boolean'],'maintenance_confirmed'=>['sometimes','accepted']]);
        if (in_array($data['action'],['download','plan','install'],true)) {
            $candidate=$updates->status()['candidate'] ?? null;
            abort_unless($candidate && hash_equals($candidate['id'], (string)($data['candidate'] ?? '')), 422, 'The selected update changed. Refresh Updates.');
            if ($data['action']==='install') abort_unless($candidate['ready'] && ($candidate['install_ready'] ?? false) && hash_equals((string)($candidate['plan_confirmation'] ?? ''),(string)($data['plan_confirmation'] ?? '')) && $request->boolean('maintenance_confirmed'), 422, 'Confirm the selected version, downtime and recovery plan.');
        }
        if ($data['action']==='preferences') abort_if($request->boolean('automatic_download') && !$request->boolean('automatic_check'),422,'Enable automatic checking before automatic downloads.');
        $action=$data['action'];unset($data['action']);
        $id=$updates->enqueue($request,$action,$data);
        return redirect()->route('settings.index',['tab'=>'updates'])->with('status', $action==='install'
            ? 'Installation request '.$id.' recorded. The local OS administrator must review and confirm the exact native installation plan; monitoring continues.'
            : 'Update request '.$id.' queued for the installed update worker. Monitoring continues.');
    }

    public function import(Request $request, UpdateRequests $updates)
    {
        $data=$request->validate(['manifest'=>['required','file','max:1024'],'filename'=>['required','regex:/\A[A-Za-z0-9][A-Za-z0-9._+\-]{1,180}\.(?:zip|tar\.gz)\z/'],
            'size'=>['required','integer','min:1','max:4294967296']]);
        $uploads=glob($updates->directory().'/*/package.bin') ?: [];
        abort_if(count($uploads)>=2,429,'Two imports are already retained. Ask the OS administrator to review or remove unused import files.');
        $id=$updates->enqueue($request,'upload',['filename'=>$data['filename'],'size'=>(int)$data['size']]);
        File::put($updates->directory().'/'.$id.'/manifest.json', $request->file('manifest')->getContent());
        return response()->json(['id'=>$id,'chunk_url'=>route('settings.updates.chunk',['ticket'=>$id])]);
    }

    public function chunk(Request $request, string $ticket, UpdateRequests $updates)
    {
        $data=$request->validate(['chunk'=>['required','file','max:4096'],'offset'=>['required','integer','min:0']]);
        $dir=$updates->directory().'/'.$ticket;abort_unless(is_file($dir.'/request.json'),404);
        $intent=json_decode(File::get($dir.'/request.json'),true);
        abort_unless($intent['actor']===$request->user()->id && $intent['action']==='upload',403);
        $stream=fopen($dir.'/package.bin','c+b');abort_unless($stream!==false,503);
        try {
            flock($stream,LOCK_EX);$size=fstat($stream)['size'];$bytes=$request->file('chunk')->getContent();
            abort_if(disk_free_space($dir)<strlen($bytes)+268435456,507,'Insufficient import storage; monitoring data has not been deleted.');
            abort_unless($size===(int)$data['offset'] && $size+strlen($bytes)<=$intent['inputs']['size'],422,'Upload offset or size changed. Restart import.');
            fseek($stream,0,SEEK_END);abort_unless(fwrite($stream,$bytes)===strlen($bytes) && fflush($stream),507,'Import write did not complete. Retain the incomplete upload for review.');
            if(function_exists('fsync')) abort_unless(fsync($stream),507,'Import could not be persisted. It is not installable.');
            $complete=$size+strlen($bytes)===$intent['inputs']['size'];
            if ($complete) $updates->saveIntent($request,$ticket,'import',$intent['inputs']);
        } finally {flock($stream,LOCK_UN);fclose($stream);}
        return response()->json(['complete'=>$complete]);
    }
}
