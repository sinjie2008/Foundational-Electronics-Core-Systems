<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use mysqli;

/** Read-only adapter over the existing Product Catalog Manager tables. */
final class PublicProductApiService
{
    private ?array $nodes = null;
    private array $paths = [];
    private array $children = [];

    public function __construct(private mysqli $db) {}

    public function root(): array
    {
        return [
            'name' => 'Public Product API',
            'sourceOfTruth' => 'existing Product Catalog Manager MySQL database',
            'readOnly' => true,
            'requiresMigration' => false,
            'requiresImport' => false,
            'requiresManualSync' => false,
            'endpointCount' => 10,
            'endpoints' => ['/api/','/api/health','/api/tree','/api/resolve/{path}','/api/categories/{path}','/api/series/{path}','/api/series/{path}/fields','/api/series/{path}/parts','/api/series/{path}/facets','/api/search?q={keyword}'],
            'tree' => $this->tree(),
        ];
    }

    public function health(): array
    {
        $r = $this->db->query('SELECT 1'); $r->close();
        return ['status' => 'ok', 'database' => 'connected', 'writeOperations' => false];
    }

    public function tree(): array
    {
        $this->load(); $out = [];
        foreach ($this->children[0] ?? [] as $id) $out[] = $this->treeNode($id);
        return $out;
    }

    public function resolve(string $path): array
    {
        $n = $this->node($path);
        return ['resource' => $this->pub($n), 'breadcrumb' => $this->breadcrumb((int)$n['id'])];
    }

    public function category(string $path): array
    {
        $n = $this->node($path);
        if ($n['type'] !== 'category') throw new CatalogApiException('CATEGORY_NOT_FOUND','Public category not found.',404);
        $items = [];
        foreach ($this->children[(int)$n['id']] ?? [] as $id) {
            $x = $this->pub($this->nodes[$id]);
            if ($x['type'] === 'series') $x['partCount'] = $this->countParts($id);
            $items[] = $x;
        }
        return ['resource'=>$this->pub($n),'breadcrumb'=>$this->breadcrumb((int)$n['id']),'children'=>$items];
    }

    public function series(string $path): array
    {
        $n = $this->seriesNode($path); $id = (int)$n['id'];
        return ['resource'=>$this->pub($n),'breadcrumb'=>$this->breadcrumb($id),'metadata'=>$this->metadata($id),'fields'=>$this->fieldsById($id),'partCount'=>$this->countParts($id)];
    }

    public function fields(string $path): array { return $this->fieldsById((int)$this->seriesNode($path)['id']); }

    public function parts(string $path, array $q): array
    {
        $n = $this->seriesNode($path); $sid = (int)$n['id']; $fields = $this->fieldsById($sid);
        $map = []; foreach ($fields as $f) $map[$f['key']] = $f;
        $rows = $this->partRows($sid, $map);
        $search = trim((string)($q['search'] ?? ''));
        $filters = $q['filter'] ?? []; if ($filters && !is_array($filters)) throw new CatalogApiException('INVALID_QUERY','filter must be an array.',422);
        foreach (array_keys((array)$filters) as $k) if (!isset($map[$k])) throw new CatalogApiException('INVALID_QUERY','Unknown filter field.',422,['field'=>$k]);
        $rows = array_values(array_filter($rows, function($r) use($search,$filters) {
            if ($search !== '' && stripos(json_encode($r, JSON_UNESCAPED_UNICODE) ?: '', $search) === false) return false;
            foreach ((array)$filters as $k=>$want) {
                $actual = $r['values'][$k] ?? null; $want = is_array($want) ? $want : [$want]; $ok = false;
                foreach ($want as $v) if (strcasecmp((string)$actual,(string)$v)===0) {$ok=true;break;}
                if (!$ok) return false;
            }
            return true;
        }));
        $sort = (string)($q['sort'] ?? 'sku'); $dir = strtolower((string)($q['direction'] ?? 'asc'));
        if (!in_array($sort,['id','sku','name'],true) && !isset($map[$sort])) throw new CatalogApiException('INVALID_QUERY','Unknown sort field.',422);
        if (!in_array($dir,['asc','desc'],true)) throw new CatalogApiException('INVALID_QUERY','direction must be asc or desc.',422);
        usort($rows, function($a,$b) use($sort,$dir) { $av=$a[$sort]??$a['values'][$sort]??''; $bv=$b[$sort]??$b['values'][$sort]??''; $c=strnatcasecmp((string)$av,(string)$bv); if(!$c)$c=$a['id']<=>$b['id']; return $dir==='desc'?-$c:$c; });
        $page = max(1,(int)($q['page']??1)); $per = max(1,min(100,(int)($q['per_page']??25))); $total=count($rows); $last=max(1,(int)ceil($total/$per)); $page=min($page,$last); $off=($page-1)*$per;
        return ['series'=>$this->pub($n),'fields'=>$fields,'parts'=>array_slice($rows,$off,$per),'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>$last]];
    }

    public function facets(string $path): array
    {
        $n=$this->seriesNode($path); $fields=$this->fieldsById((int)$n['id']); $map=[]; foreach($fields as $f)$map[$f['key']]=$f; $rows=$this->partRows((int)$n['id'],$map); $out=[];
        foreach($fields as $f){$c=[];foreach($rows as $r){$v=$r['values'][$f['key']]??null;if($v===null||$v==='')continue;$c[(string)$v]=($c[(string)$v]??0)+1;}ksort($c,SORT_NATURAL|SORT_FLAG_CASE);$vals=[];foreach($c as $v=>$count)$vals[]=['value'=>$v,'count'=>$count];$out[]=['key'=>$f['key'],'label'=>$f['label'],'type'=>$f['type'],'values'=>$vals];}
        return ['series'=>$this->pub($n),'totalParts'=>count($rows),'facets'=>$out];
    }

    public function search(string $q): array
    {
        $q=trim($q); if($q==='')return ['query'=>'','nodes'=>[],'parts'=>[]]; $this->load(); $nodes=[];
        foreach($this->nodes as $n) if(stripos($n['name'],$q)!==false)$nodes[]=$this->pub($n);
        $like='%'.$q.'%'; $s=$this->db->prepare('SELECT id,series_id,sku,name,description FROM product WHERE sku LIKE ? OR name LIKE ? OR description LIKE ? ORDER BY name,id LIMIT 100'); $s->bind_param('sss',$like,$like,$like);$s->execute();$r=$s->get_result();$parts=[];
        while($p=$r->fetch_assoc()){if(!isset($this->nodes[(int)$p['series_id']]))continue;$parts[]=['id'=>(int)$p['id'],'sku'=>$p['sku'],'name'=>$p['name'],'description'=>$p['description'],'series'=>$this->pub($this->nodes[(int)$p['series_id']])];}$s->close();
        return ['query'=>$q,'nodes'=>$nodes,'parts'=>$parts];
    }

    private function load(): void
    {
        if($this->nodes!==null)return; $r=$this->db->query('SELECT id,parent_id,name,type,display_order FROM category ORDER BY display_order,name,id');$rows=[];$children=[];
        while($n=$r->fetch_assoc()){$id=(int)$n['id'];$pid=$n['parent_id']===null?0:(int)$n['parent_id'];$n['id']=$id;$n['parent_id']=$pid?:null;$n['display_order']=(int)$n['display_order'];$n['slug']=$this->slug($n['name']);$rows[$id]=$n;$children[$pid][]=$id;}$r->close();
        foreach($children as $ids){$counts=[];foreach($ids as $id)$counts[$rows[$id]['slug']]=($counts[$rows[$id]['slug']]??0)+1;foreach($ids as $id)if($counts[$rows[$id]['slug']]>1)$rows[$id]['slug'].='-'.$id;}
        $paths=[];$path=function($id,$seen=[])use(&$path,&$rows,&$paths){if(isset($paths[$id]))return$paths[$id];if(isset($seen[$id]))throw new CatalogApiException('INVALID_HIERARCHY','Catalog hierarchy contains a cycle.',500);$seen[$id]=1;$p=$rows[$id]['parent_id'];return$paths[$id]=($p&&isset($rows[$p])?$path($p,$seen).'/':'').$rows[$id]['slug'];};
        $index=[];foreach(array_keys($rows)as$id){$rows[$id]['path']=$path($id);$index[$rows[$id]['path']]=$id;}$this->nodes=$rows;$this->paths=$index;$this->children=$children;
    }

    private function treeNode(int $id): array {$n=$this->pub($this->nodes[$id]);$n['children']=[];foreach($this->children[$id]??[]as$c)$n['children'][]=$this->treeNode($c);if($n['type']==='series')$n['partCount']=$this->countParts($id);return$n;}
    private function node(string $path): array {$this->load();$path=trim(rawurldecode($path),'/');if($path===''||str_contains($path,'..'))throw new CatalogApiException('INVALID_PATH','A valid catalog path is required.',422);$id=$this->paths[$path]??null;if(!$id)throw new CatalogApiException('NOT_FOUND','Public catalog path not found.',404,['path'=>$path]);return$this->nodes[$id];}
    private function seriesNode(string $path): array {$n=$this->node($path);if($n['type']!=='series')throw new CatalogApiException('SERIES_NOT_FOUND','Public series not found.',404);return$n;}
    private function pub(array $n): array {return ['id'=>(int)$n['id'],'parentId'=>$n['parent_id']===null?null:(int)$n['parent_id'],'name'=>$n['name'],'type'=>$n['type'],'slug'=>$n['slug'],'path'=>$n['path'],'displayOrder'=>(int)$n['display_order']];}
    private function breadcrumb(int $id): array {$this->load();$o=[];$seen=[];while(isset($this->nodes[$id])&&!isset($seen[$id])){$seen[$id]=1;array_unshift($o,$this->pub($this->nodes[$id]));$p=$this->nodes[$id]['parent_id'];if($p===null)break;$id=(int)$p;}return$o;}
    private function countParts(int $sid): int {$s=$this->db->prepare('SELECT COUNT(1) FROM product WHERE series_id=?');$s->bind_param('i',$sid);$s->execute();$c=(int)($s->get_result()->fetch_row()[0]??0);$s->close();return$c;}

    private function fieldsById(int $sid): array
    {
        $s=$this->db->prepare("SELECT id,field_key,label,field_type,default_value,sort_order,is_required FROM series_custom_field WHERE series_id=? AND field_scope='product_attribute' AND COALESCE(is_public_portal_hidden,0)=0 ORDER BY sort_order,id");$s->bind_param('i',$sid);$s->execute();$r=$s->get_result();$o=[];
        while($f=$r->fetch_assoc())$o[]=['id'=>(int)$f['id'],'key'=>$f['field_key'],'label'=>$f['label'],'type'=>$f['field_type'],'defaultValue'=>$f['default_value'],'sortOrder'=>(int)$f['sort_order'],'required'=>(int)$f['is_required']===1];$s->close();return$o;
    }

    private function metadata(int $sid): array
    {
        $s=$this->db->prepare("SELECT f.field_key,f.label,f.field_type,f.default_value,f.sort_order,v.value FROM series_custom_field f LEFT JOIN series_custom_field_value v ON v.series_custom_field_id=f.id AND v.series_id=f.series_id WHERE f.series_id=? AND f.field_scope='series_metadata' AND COALESCE(f.is_public_portal_hidden,0)=0 ORDER BY f.sort_order,f.id");$s->bind_param('i',$sid);$s->execute();$r=$s->get_result();$o=[];
        while($f=$r->fetch_assoc())$o[]=['key'=>$f['field_key'],'label'=>$f['label'],'type'=>$f['field_type'],'value'=>$f['value']??$f['default_value'],'sortOrder'=>(int)$f['sort_order']];$s->close();return$o;
    }

    private function partRows(int $sid,array $fields): array
    {
        $s=$this->db->prepare('SELECT id,sku,name,description FROM product WHERE series_id=? ORDER BY id');$s->bind_param('i',$sid);$s->execute();$r=$s->get_result();$parts=[];$ids=[];$fid=[];
        foreach($fields as$k=>$f)$fid[(int)$f['id']]=$k;
        while($p=$r->fetch_assoc()){$id=(int)$p['id'];$vals=[];foreach($fields as$k=>$f)$vals[$k]=$f['defaultValue'];$parts[$id]=['id'=>$id,'sku'=>$p['sku'],'name'=>$p['name'],'description'=>$p['description'],'values'=>$vals];$ids[]=$id;}$s->close();
        if(!$ids||!$fid)return array_values($parts);$a=implode(',',array_map('intval',$ids));$b=implode(',',array_map('intval',array_keys($fid)));$r=$this->db->query("SELECT product_id,series_custom_field_id,value FROM product_custom_field_value WHERE product_id IN ($a) AND series_custom_field_id IN ($b)");
        while($v=$r->fetch_assoc()){$pid=(int)$v['product_id'];$k=$fid[(int)$v['series_custom_field_id']]??null;if($k!==null&&isset($parts[$pid]))$parts[$pid]['values'][$k]=$v['value'];}$r->close();return array_values($parts);
    }

    private function slug(string $v): string {$v=strtolower(trim($v));$v=preg_replace('/[^\pL\pN]+/u','-',$v)??'';$v=trim($v,'-');return$v!==''?$v:'item';}
}
