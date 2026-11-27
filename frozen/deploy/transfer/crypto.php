<?php
// Installed transfer-authority helper. Private key path comes only from protected local state.
try {
    $i=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
    if ($i['action']==='public') {
        $key=openssl_pkey_get_private(file_get_contents($i['private_path']));
        $details=openssl_pkey_get_details($key);
        if ($details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<2048) throw new RuntimeException();
        echo json_encode(['public_key'=>$details['key']],JSON_THROW_ON_ERROR);
    } elseif ($i['action']==='certificate-public') {
        $certificate=openssl_x509_read(file_get_contents($i['certificate_path']));
        $key=openssl_pkey_get_public($certificate);
        $details=openssl_pkey_get_details($key);
        if (!$details || !isset($details['key'])) throw new RuntimeException();
        echo json_encode(['public_key'=>$details['key']],JSON_THROW_ON_ERROR);
    } elseif ($i['action']==='sign') {
        $key=openssl_pkey_get_private(file_get_contents($i['private_path']));
        if (!openssl_sign(base64_decode($i['data'],true),$signature,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException();
        echo json_encode(['signature'=>base64_encode($signature)],JSON_THROW_ON_ERROR);
    } else throw new RuntimeException();
} catch (Throwable) {fwrite(STDERR,'Protected transfer signing failed.');exit(1);}
