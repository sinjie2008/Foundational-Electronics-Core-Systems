<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\PublicProductApiService;
use CatalogSuite\Support\Db;
use Throwable;

/** GET-only browser entrypoint for the live public product API. */
final class PublicProductApiController
{
    public function run(): void
    {
        $out = new HttpResponder(); $out->setCorrelationId(Request::correlationId());
        try {
            Transport::header('Cache-Control: no-store'); Transport::header('X-Content-Type-Options: nosniff');
            if (Request::method() !== 'GET') { Transport::header('Allow: GET'); throw new CatalogApiException('METHOD_NOT_ALLOWED','Only GET is supported.',405); }
            $route=$this->route(); $api=new PublicProductApiService(Db::connection());
            $data=match(true){
                $route==='' => $api->root(),
                $route==='health' => $api->health(),
                $route==='tree' => $api->tree(),
                $route==='search' => $api->search((string)($_GET['q']??'')),
                str_starts_with($route,'resolve/') => $api->resolve(substr($route,8)),
                str_starts_with($route,'categories/') => $api->category(substr($route,11)),
                str_starts_with($route,'series/') => $this->series($api,substr($route,7)),
                default => throw new CatalogApiException('NOT_FOUND','API route not found.',404),
            };
            $out->sendJson(['success'=>true,'data'=>$data]);
        } catch (CatalogApiException $e) {
            $out->sendError($e->getErrorCode(),$e->getMessage(),$e->getStatusCode(),$e->getDetails());
        } catch (Throwable $e) {
            error_log('Public Product API failure: '.$e->getMessage());
            $out->sendError('INTERNAL_ERROR','The public product API is unavailable. Check the existing database connection and tables.',500);
        }
    }

    private function series(PublicProductApiService $api,string $path): mixed
    {
        $path=trim($path,'/');
        try { return $api->series($path); } catch (CatalogApiException $e) { if($e->getStatusCode()!==404)throw $e; }
        foreach(['fields','parts','facets'] as $sub){$suffix='/'.$sub;if(str_ends_with($path,$suffix)){$p=substr($path,0,-strlen($suffix));return match($sub){'fields'=>$api->fields($p),'parts'=>$api->parts($p,$_GET),'facets'=>$api->facets($p)};}}
        throw new CatalogApiException('SERIES_NOT_FOUND','Public series not found.',404);
    }

    private function route(): string
    {
        if(isset($_GET['route']))return trim((string)$_GET['route'],'/');
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        if(preg_match('/%(?![0-9a-f]{2})/i',$path)===1)throw new CatalogApiException('INVALID_PATH','Invalid path encoding.',422);
        $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/api/index.php'));$base=rtrim(str_replace('\\','/',dirname($script)),'/');
        if($base!==''&&str_starts_with($path,$base))$path=substr($path,strlen($base));$path=trim($path,'/');
        if($path==='index.php')return '';if(str_starts_with($path,'index.php/'))$path=substr($path,10);
        return trim(implode('/',array_map('rawurldecode',$path===''?[]:explode('/',$path))),'/');
    }
}
