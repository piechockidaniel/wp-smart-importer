<?php
defined('ABSPATH') || exit;
/**
 * WPSI_Expression_Evaluator — safe, eval()-free expression engine.
 * Supports: {vars}, strings, numbers, booleans, ternary, ==|!=|>|>=|<|<=|&&|||!|.
 * Functions: intval,floatval,strtolower,strtoupper,trim,strlen,round,abs,empty,
 *            bool,contains,starts_with,ends_with,default,max,min,isset_val
 */
class WPSI_Expression_Evaluator {
    const T_STR='S'; const T_NUM='N'; const T_BOOL='B'; const T_NULL='NL';
    const T_VAR='V'; const T_ID='I'; const T_OP='O'; const T_LP='('; const T_RP=')';
    const T_COMMA=','; const T_Q='?'; const T_COL=':'; const T_DOT='.'; const T_EOF='E';
    private array $tokens=[]; private int $pos=0; private array $record=[];

    public static function evaluate(string $expr, array $record): string {
        $expr=trim($expr); if($expr==='')return '';
        try{
            $ev=new self(); $ev->record=$record; $ev->tokenize($expr);
            $r=$ev->ternary();
            if($ev->peek()['t']!==self::T_EOF)throw new \RuntimeException('Unexpected end');
            return self::str($r);
        }catch(\Throwable $e){return '';}
    }

    private function tokenize(string $e): void {
        $this->tokens=[]; $this->pos=0; $len=strlen($e); $i=0;
        while($i<$len){
            if(ctype_space($e[$i])){$i++;continue;}
            if($e[$i]==='{'){$end=strpos($e,'}',$i);if($end===false)throw new \RuntimeException('Unclosed {');
                $this->tokens[]=['t'=>self::T_VAR,'v'=>substr($e,$i+1,$end-$i-1)];$i=$end+1;continue;}
            if($e[$i]==="'"||$e[$i]==='"'){$q=$e[$i];$j=$i+1;$s='';
                while($j<$len&&$e[$j]!==$q){if($e[$j]==='\\'&&$j+1<$len){$s.=$e[++$j];}else{$s.=$e[$j];}$j++;}
                $this->tokens[]=['t'=>self::T_STR,'v'=>$s];$i=$j+1;continue;}
            if(ctype_digit($e[$i])||($e[$i]==='-'&&$i+1<$len&&ctype_digit($e[$i+1]))){
                $j=$i; if($e[$j]==='-')$j++;
                while($j<$len&&(ctype_digit($e[$j])||$e[$j]==='.'))$j++;
                $this->tokens[]=['t'=>self::T_NUM,'v'=>(float)substr($e,$i,$j-$i)];$i=$j;continue;}
            if($i+1<$len){$two=substr($e,$i,2);
                if(in_array($two,['>=','<=','==','!=','&&','||'],true)){$this->tokens[]=['t'=>self::T_OP,'v'=>$two];$i+=2;continue;}}
            switch($e[$i]){
                case'(':$this->tokens[]=['t'=>self::T_LP];$i++;continue 2;
                case')':$this->tokens[]=['t'=>self::T_RP];$i++;continue 2;
                case',':$this->tokens[]=['t'=>self::T_COMMA];$i++;continue 2;
                case'?':$this->tokens[]=['t'=>self::T_Q];$i++;continue 2;
                case':':$this->tokens[]=['t'=>self::T_COL];$i++;continue 2;
                case'.':$this->tokens[]=['t'=>self::T_DOT];$i++;continue 2;
                case'>':case'<':case'!':$this->tokens[]=['t'=>self::T_OP,'v'=>$e[$i]];$i++;continue 2;
            }
            if(ctype_alpha($e[$i])||$e[$i]==='_'){
                $j=$i; while($j<$len&&(ctype_alnum($e[$j])||$e[$j]==='_'))$j++;
                $w=substr($e,$i,$j-$i);
                $t=match(strtolower($w)){'true'=>self::T_BOOL,'false'=>self::T_BOOL,'null'=>self::T_NULL,default=>self::T_ID};
                $this->tokens[]=['t'=>$t,'v'=>$w];$i=$j;continue;
            }
            throw new \RuntimeException("Unexpected char '{$e[$i]}'");
        }
        $this->tokens[]=['t'=>self::T_EOF];
    }
    private function peek():array{return $this->tokens[$this->pos]??['t'=>self::T_EOF];}
    private function consume():array{return $this->tokens[$this->pos++]??['t'=>self::T_EOF];}
    private function expect(string $t):array{$tok=$this->consume();if($tok['t']!==$t)throw new \RuntimeException("Expected $t got {$tok['t']}");return $tok;}

    private function ternary():mixed{
        $c=$this->or_();
        if($this->peek()['t']===self::T_Q){$this->consume();$th=$this->ternary();$this->expect(self::T_COL);$el=$this->ternary();return self::truthy($c)?$th:$el;}
        return $c;
    }
    private function or_():mixed{$l=$this->and_();while($this->peek()===['t'=>self::T_OP,'v'=>'||']){$this->consume();$r=$this->and_();$l=self::truthy($l)||self::truthy($r);}return $l;}
    private function and_():mixed{$l=$this->not_();while($this->peek()===['t'=>self::T_OP,'v'=>'&&']){$this->consume();$r=$this->not_();$l=self::truthy($l)&&self::truthy($r);}return $l;}
    private function not_():mixed{if($this->peek()===['t'=>self::T_OP,'v'=>'!']){$this->consume();return !self::truthy($this->not_());}return $this->cmp();}
    private function cmp():mixed{
        $l=$this->cat();
        $ops=['==','!=','>','>=','<','<='];
        while($this->peek()['t']===self::T_OP&&in_array($this->peek()['v']??'',$ops,true)){
            $op=$this->consume()['v'];$r=$this->cat();
            $l=is_numeric($l)&&is_numeric($r)?match($op){'=='=>(float)$l==(float)$r,'!='=>(float)$l!=(float)$r,'>'=>(float)$l>(float)$r,'>='=>(float)$l>=(float)$r,'<'=>(float)$l<(float)$r,'<='=>(float)$l<=(float)$r,default=>false}:match($op){'=='=>$l==$r,'!='=>$l!=$r,'>'=>$l>$r,'>='=>$l>=$r,'<'=>$l<$r,'<='=>$l<=$r,default=>false};
        }
        return $l;
    }
    private function cat():mixed{$l=$this->primary();while($this->peek()['t']===self::T_DOT){$this->consume();$r=$this->primary();$l=self::str($l).self::str($r);}return $l;}
    private function primary():mixed{
        $t=$this->peek();
        if($t['t']===self::T_LP){$this->consume();$v=$this->ternary();$this->expect(self::T_RP);return $v;}
        if($t['t']===self::T_VAR){$this->consume();return(string)(WPSI_Parser_Base::get_value($this->record,$t['v'])??'');}
        if($t['t']===self::T_STR){$this->consume();return $t['v'];}
        if($t['t']===self::T_NUM){$this->consume();return $t['v'];}
        if($t['t']===self::T_NULL){$this->consume();return null;}
        if($t['t']===self::T_BOOL){$this->consume();return strtolower($t['v'])==='true';}
        if($t['t']===self::T_ID){
            $this->consume();$name=strtolower($t['v']);$this->expect(self::T_LP);
            $args=[];
            if($this->peek()['t']!==self::T_RP){$args[]=$this->ternary();while($this->peek()['t']===self::T_COMMA){$this->consume();$args[]=$this->ternary();}}
            $this->expect(self::T_RP);
            return match($name){
                'intval'      =>intval($args[0]??0),
                'floatval'    =>floatval($args[0]??0),
                'strval'      =>(string)($args[0]??''),
                'strtolower'  =>strtolower(self::str($args[0]??'')),
                'strtoupper'  =>strtoupper(self::str($args[0]??'')),
                'trim'        =>trim(self::str($args[0]??'')),
                'strlen'      =>strlen(self::str($args[0]??'')),
                'round'       =>round(floatval($args[0]??0),(int)($args[1]??0)),
                'abs'         =>abs(floatval($args[0]??0)),
                'empty'       =>empty($args[0])||$args[0]===''||$args[0]==='0'||$args[0]===null,
                'isset_val'   =>isset($args[0])&&$args[0]!==''&&$args[0]!==null,
                'bool'        =>self::truthy($args[0]??null),
                'contains'    =>str_contains(self::str($args[0]??''),self::str($args[1]??'')),
                'starts_with' =>str_starts_with(self::str($args[0]??''),self::str($args[1]??'')),
                'ends_with'   =>str_ends_with(self::str($args[0]??''),self::str($args[1]??'')),
                'default'     =>(isset($args[0])&&$args[0]!==''&&$args[0]!==null)?$args[0]:($args[1]??''),
                'max'         =>max(floatval($args[0]??0),floatval($args[1]??0)),
                'min'         =>min(floatval($args[0]??0),floatval($args[1]??0)),
                default       =>throw new \RuntimeException("Unknown: {$name}()"),
            };
        }
        throw new \RuntimeException("Unexpected token {$t['t']}");
    }
    private static function truthy(mixed $v): bool {
        if(is_bool($v))return $v; if(is_null($v))return false;
        if(is_numeric($v))return(float)$v!==0.0;
        return $v!==''&&$v!=='0'&&$v!=='false';
    }
    private static function str(mixed $v): string {
        if(is_bool($v))return $v?'true':'false'; if(is_null($v))return '';
        return(string)$v;
    }
}
