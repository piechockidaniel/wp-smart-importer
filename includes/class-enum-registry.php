<?php
defined('ABSPATH') || exit;
class WPSI_Enum_Registry {
    public static function get(string $f,string $p='post'):?array{return self::all($p)[$f]??null;}
    public static function all(string $p='post'):array{
        $h=self::core();
        if(in_array($p,['product','product_variation'],true))$h=array_merge($h,self::woo());
        if(in_array($p,['shop_order','wc-order'],true))$h=array_merge($h,self::orders());
        return apply_filters('wpsi_enum_hints',$h,$p);
    }
    public static function all_keys_for_js(string $p='post'):array{
        $out=[];
        foreach(self::all($p) as $k=>$h)$out[$k]=['label'=>$h['label'],'type'=>$h['type'],'note'=>$h['note']??null,'values'=>$h['values']];
        return $out;
    }
    private static function v(string $val,string $lbl,?int $i=null,bool $d=false):array{return['value'=>$val,'label'=>$lbl,'int_map'=>$i,'default'=>$d];}
    private static function core():array{return[
        'post_status'=>['label'=>'Post Status','type'=>'string','note'=>'Controls visibility.','values'=>[
            self::v('publish','Published',null,true),self::v('draft','Draft'),self::v('pending','Pending review'),
            self::v('private','Private'),self::v('future','Scheduled'),self::v('trash','Trashed'),
        ]],
        'comment_status'=>['label'=>'Comment Status','type'=>'string','note'=>null,'values'=>[self::v('open','Open',1,true),self::v('closed','Closed',0)]],
        'ping_status'=>['label'=>'Ping Status','type'=>'string','note'=>null,'values'=>[self::v('open','Open',1,true),self::v('closed','Closed',0)]],
    ];}
    private static function woo():array{return[
        '_stock_status'=>['label'=>'Stock Status','type'=>'string','note'=>'Sets availability badge.','values'=>[
            self::v('instock','In stock',1,true),self::v('outofstock','Out of stock',0),self::v('onbackorder','On backorder',2),
        ]],
        '_manage_stock'=>['label'=>'Manage Stock','type'=>'bool_string','note'=>'WC stores yes/no strings.','values'=>[self::v('yes','Yes - track qty',1),self::v('no','No',0,true)]],
        '_backorders'=>['label'=>'Allow Backorders','type'=>'string','note'=>null,'values'=>[self::v('no','Do not allow',0,true),self::v('notify','Allow but notify',1),self::v('yes','Allow',2)]],
        'catalog_visibility'=>['label'=>'Catalog Visibility','type'=>'string','note'=>'WC product attribute.','values'=>[
            self::v('visible','Shop and search',null,true),self::v('catalog','Shop only'),self::v('search','Search only'),self::v('hidden','Hidden'),
        ]],
        '_virtual'=>['label'=>'Virtual','type'=>'bool_string','note'=>null,'values'=>[self::v('yes','Virtual',1),self::v('no','Not virtual',0,true)]],
        '_downloadable'=>['label'=>'Downloadable','type'=>'bool_string','note'=>null,'values'=>[self::v('yes','Yes',1),self::v('no','No',0,true)]],
        '_tax_status'=>['label'=>'Tax Status','type'=>'string','note'=>null,'values'=>[self::v('taxable','Taxable',null,true),self::v('shipping','Shipping only'),self::v('none','None')]],
        '_tax_class'=>['label'=>'Tax Class','type'=>'string','note'=>'Empty = Standard.','values'=>[self::v('','Standard (empty)',null,true),self::v('reduced-rate','Reduced rate'),self::v('zero-rate','Zero rate')]],
        '_featured'=>['label'=>'Featured','type'=>'bool_string','note'=>null,'values'=>[self::v('yes','Featured',1),self::v('no','No',0,true)]],
        '_sold_individually'=>['label'=>'Sold individually','type'=>'bool_string','note'=>null,'values'=>[self::v('yes','Yes',1),self::v('no','No',0,true)]],
    ];}
    private static function orders():array{return[
        'post_status'=>['label'=>'Order Status','type'=>'string','note'=>'WC statuses prefixed with "wc-".','values'=>[
            self::v('wc-pending','Pending',null,true),self::v('wc-processing','Processing'),self::v('wc-on-hold','On hold'),
            self::v('wc-completed','Completed'),self::v('wc-cancelled','Cancelled'),self::v('wc-refunded','Refunded'),self::v('wc-failed','Failed'),
        ]],
    ];}
}
