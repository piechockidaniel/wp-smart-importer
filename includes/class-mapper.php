<?php
defined('ABSPATH') || exit;
class WPSI_Mapper {
    public const CORE_FIELDS = ['post_title','post_content','post_excerpt','post_status','post_date','post_author','post_name','post_parent','menu_order','comment_status','ping_status','post_password','guid'];
    public static function apply(array $record, array $field_map): array {
        $post_data=[]; $meta=[]; $tax=[];
        foreach($field_map as $rule){
            if(empty($rule['destination']))continue;
            $value=self::resolve($record,$rule);
            $dtype=$rule['destination_type']??'meta';
            $dest=$rule['destination'];
            if($dtype==='core'){$post_data[$dest]=$value;}
            elseif($dtype==='tax'){$terms=array_filter(array_map('trim',explode(',',(string)$value)));if(!empty($terms))$tax[$dest]=$terms;}
            else{$meta[$dest]=$value;}
        }
        return compact('post_data','meta','tax');
    }
    public static function resolve(array $record, array $rule): string {
        $st=$rule['source_type']??'field';
        $value=match($st){
            'static'    =>(string)($rule['static_value']??''),
            'template'  =>preg_replace_callback('/\{([^}]+)\}/',fn($m)=>(string)(WPSI_Parser_Base::get_value($record,$m[1])??''),$rule['template']??''),
            'field'     =>(string)(WPSI_Parser_Base::get_value($record,$rule['source']??'')??''),
            'expression'=>WPSI_Expression_Evaluator::evaluate((string)($rule['expression']??''),$record),
            default     =>'',
        };
        if(!empty($rule['regex'])&&$value!==''){
            if(@preg_match($rule['regex'],$value,$m))$value=$m[(int)($rule['regex_group']??0)]??$value;
        }
        if($value===''&&!empty($rule['default']))$value=(string)$rule['default'];
        return $value;
    }
    public static function get_destination_options(string $pt='post'): array {
        $o=[];
        foreach(self::CORE_FIELDS as $f)$o['core'][]=['key'=>$f,'label'=>$f];
        $reg=get_registered_meta_keys('post',$pt);
        foreach(array_keys($reg) as $k)$o['meta'][]=['key'=>$k,'label'=>$k.' (registered)'];
        foreach(['_thumbnail_id','_price','_regular_price','_sale_price','_sku','_stock','_stock_status','_manage_stock','_weight','_length','_width','_height','_backorders','catalog_visibility','_virtual','_downloadable','_featured','_sold_individually','_tax_status','_tax_class'] as $k)
            if(!isset($reg[$k]))$o['meta'][]=['key'=>$k,'label'=>$k];
        foreach(get_object_taxonomies($pt,'objects') as $slug=>$obj)
            $o['tax'][]=['key'=>$slug,'label'=>$obj->label." ($slug)"];
        return $o;
    }
}
