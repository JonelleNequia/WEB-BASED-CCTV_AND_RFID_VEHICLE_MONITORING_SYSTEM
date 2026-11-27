<?php
// Target/source integration kernel only, with private fixture DB/storage and explicit owner.
if (getenv('PHILCST_D5B_FIXTURE')!=='1' || !str_contains((string)getenv('DB_DATABASE'),'d5b-')) exit(90);
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->useStoragePath(getenv('PHILCST_D5B_STORAGE'));
$packet=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
$headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_API_KEY'=>'synthetic-d5b-key','REMOTE_ADDR'=>'127.0.0.1'];
if (getenv('PHILCST_D5D_OWNER')!=='') $headers['HTTP_X_INSTALLATION_OWNER']=getenv('PHILCST_D5D_OWNER');
$r=Illuminate\Http\Request::create('http://127.0.0.1:8080/api/v1/integration/deliveries','POST',[],[],[],$headers,json_encode($packet));
$k=$app->make(Illuminate\Contracts\Http\Kernel::class);$s=$k->handle($r);$k->terminate($r,$s);
echo json_encode(['status'=>$s->getStatusCode(),'body'=>json_decode($s->getContent(),true)]);
