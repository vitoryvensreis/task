<?php
declare(strict_types=1);

// Run on the TaskEsc VPS console. API keys must never be entered as argv.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$file=$root.'/config/config.php';
if(!is_file($file)||is_link($file)){fwrite(STDERR,"Configuração privada não encontrada ou não segura.\n");exit(1);}
if(!function_exists('stream_isatty')||!stream_isatty(STDIN)){
    fwrite(STDERR,"Execute em um terminal SSH interativo; não transmita chaves em comandos, logs ou CI.\n");exit(1);
}
function secretPrompt(string $label): string {
    fwrite(STDOUT,$label);
    // stty changes the current TTY only; no user input appears in a shell command.
    system('stty -echo 2>/dev/null');
    try{$line=fgets(STDIN);}finally{system('stty echo 2>/dev/null');fwrite(STDOUT,"\n");}
    if(!is_string($line))throw new RuntimeException('Não foi possível ler a chave.');
    return trim($line);
}
try{
    $current=require $file;
    if(!is_array($current))throw new RuntimeException('Configuração inválida.');
    fwrite(STDOUT,"TaskEsc AI — Gemini principal / Groq alternativo\n");
    fwrite(STDOUT,"Use apenas chaves NOVAS geradas nos consoles oficiais.\n");
    $gemini=secretPrompt('Nova chave da API Gemini: ');
    if(strlen($gemini)<21||str_contains($gemini,"\n"))throw new RuntimeException('Chave Gemini ausente ou inválida.');
    $groq=secretPrompt('Nova chave Groq (opcional; ENTER para desabilitar fallback): ');
    if($groq!==''&&(strlen($groq)<21||str_contains($groq,"\n")))throw new RuntimeException('Chave Groq inválida.');
    $old=$current['ai_whatsapp']??[];
    if(!is_array($old))$old=[];
    $current['ai_whatsapp']=array_merge($old,[
        'enabled'=>true,
        'provider'=>'gemini','fallback_provider'=>'groq',
        'gemini_api_key'=>$gemini,'gemini_model'=>'gemini-3.8-flash',
        'groq_api_key'=>$groq,'groq_model'=>'llama-3.3-70b-versatile',
    ]);
    unset($current['ai_whatsapp']['model']);
    $content="<?php\n// TaskEsc private runtime config; NEVER commit this file.\nreturn ".var_export($current,true).";\n";
    $directory=dirname($file);
    $tmp=tempnam($directory,'.ai-config-');
    if($tmp===false)throw new RuntimeException('Não foi possível preparar arquivo temporário.');
    try{
        $perms=fileperms($file)&0777;
        if(file_put_contents($tmp,$content,LOCK_EX)!==strlen($content))throw new RuntimeException('Escrita incompleta.');
        if(!chmod($tmp,$perms))throw new RuntimeException('Permissões não puderam ser preservadas.');
        if(fileowner($tmp)!==fileowner($file)&&!@chown($tmp,fileowner($file)))throw new RuntimeException('Proprietário não pôde ser preservado.');
        if(filegroup($tmp)!==filegroup($file)&&!@chgrp($tmp,filegroup($file)))throw new RuntimeException('Grupo não pôde ser preservado.');
        // PHP syntax check on private temp without executing contents.
        $command='php -l '.escapeshellarg($tmp).' 2>/dev/null';
        exec($command,$output,$exit);
        if($exit!==0)throw new RuntimeException('Configuração produzida não passou na sintaxe.');
        if(!rename($tmp,$file))throw new RuntimeException('Não foi possível substituir configuração.');
    }finally{if(is_file($tmp))@unlink($tmp);}
    fwrite(STDOUT,"Configuração salva localmente na VPS, sem imprimir chaves.\n");
    fwrite(STDOUT,"Execute: sudo systemctl restart taskesc-ai-whatsapp-worker.service\n");
    fwrite(STDOUT,"Depois: php bin/ai-whatsapp-worker.php --health\n");
}catch(Throwable $e){
    // Don't echo exception objects that could contain private config values.
    fwrite(STDERR,"Falha na configuração privada: verifique permissões, terminal e chaves.\n");
    exit(1);
}
