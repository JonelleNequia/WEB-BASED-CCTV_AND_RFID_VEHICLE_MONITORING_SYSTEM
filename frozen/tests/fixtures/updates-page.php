<?php
require __DIR__.'/ui-workspace.php';
Illuminate\Support\Facades\Auth::login($user);
$app['view']->share('errors',new Illuminate\Support\ViewErrorBag());
$updates=new class extends App\Services\UpdateRequests {
    public function status(): array {return ['provisioned'=>true,'installed'=>['version'=>'1.0.0','platform'=>'macos-arm64','source'=>['sha256'=>str_repeat('a',64)]],
        'preferences'=>['channel'=>'stable','automatic_check'=>false,'automatic_download'=>false],'last_check'=>['outcome'=>'check unavailable'],
        'candidate'=>['id'=>str_repeat('b',64),'ready'=>true,'install_ready'=>true,'plan_confirmation'=>str_repeat('c',64),
            'manifest'=>['signed'=>['version'=>'1.1.0']],'notes'=>'Synthetic <script>alert("untrusted release note")</script>',
            'requirements'=>['required_disk_bytes'=>2147483648,'downtime'=>'Monitoring stops during this update.','recovery'=>'Retained verified recovery set.']]];}
};
$request=Illuminate\Http\Request::create('/settings?tab=updates');$request->setUserResolver(fn ()=>$user);
try {file_put_contents($directory.'/updates.html',app(App\Http\Controllers\UpdateController::class)->index($request,$updates)->render());}
catch (Throwable $error) {fwrite(STDERR,$error->getMessage());exit(1);}
