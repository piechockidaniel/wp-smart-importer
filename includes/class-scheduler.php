<?php
defined('ABSPATH') || exit;
class WPSI_Scheduler {
    const HOOK_CHECK='wpsi_check_schedules';
    const HOOK_RUN='wpsi_run_job';
    public static function init(): void {
        add_filter('cron_schedules',[__CLASS__,'add_schedules']);
        add_action(self::HOOK_CHECK,[__CLASS__,'check_and_run_due']);
        add_action(self::HOOK_RUN,  [__CLASS__,'handle_direct_run']);
        if(!wp_next_scheduled(self::HOOK_CHECK))wp_schedule_event(time(),'wpsi_every_5min',self::HOOK_CHECK);
    }
    public static function deactivate(): void {wp_clear_scheduled_hook(self::HOOK_CHECK);wp_clear_scheduled_hook(self::HOOK_RUN);}
    public static function add_schedules(array $s): array {
        $s['wpsi_every_5min']=['interval'=>5*MINUTE_IN_SECONDS,'display'=>'Every 5 min (WPSI checker)'];
        return $s;
    }
    public static function check_and_run_due(): void {
        foreach(WPSI_Database::get_due_jobs() as $job)WPSI_Importer::run($job);
    }
    public static function handle_direct_run(int $id): void {
        $job=WPSI_Database::get_job($id);
        if($job&&$job['status']==='active')WPSI_Importer::run($job);
    }
    public static function calc_next_run(array $job): ?string {
        $t=$job['schedule_type']??'none';
        if($t==='none')return null;
        if($t==='cron')return self::next_cron($job['cron_expression']??'0 * * * *');
        $days=array_filter(array_map('trim',explode(',',$job['schedule_days']??'')));
        $times=is_string($job['schedule_times']??'')?json_decode($job['schedule_times'],true):($job['schedule_times']??[]);
        $tz=$job['schedule_timezone']??'UTC';
        if(empty($days)||empty($times))return gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS);
        return self::next_day_time($days,$times,$tz);
    }
    private static function next_day_time(array $days,array $times,string $tz): string {
        try{$TZ=new \DateTimeZone($tz);}catch(\Exception $e){$TZ=new \DateTimeZone('UTC');}
        $map=['Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7];
        $nums=array_filter(array_map(fn($d)=>$map[$d]??null,$days));
        $now=new \DateTimeImmutable('now',$TZ);
        $best=null;
        for($o=0;$o<=7;$o++){
            $day=$now->modify("+{$o} days");
            if(!in_array((int)$day->format('N'),$nums,true))continue;
            foreach($times as $t){
                $t=trim($t);
                if(!preg_match('/^\d{1,2}:\d{2}(am|pm)?$/i',$t))continue;
                try{$c=new \DateTimeImmutable($day->format('Y-m-d').' '.self::parse_time($t),$TZ);}catch(\Exception $e){continue;}
                if($c<=$now)continue;
                if($best===null||$c<$best)$best=$c;
            }
            if($best!==null)break;
        }
        return $best?$best->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS);
    }
    private static function parse_time(string $t): string {
        $t=strtolower(trim($t));
        if(str_ends_with($t,'am')||str_ends_with($t,'pm')){$ts=strtotime($t);return $ts?date('H:i:s',$ts):'00:00:00';}
        [$h,$m]=explode(':',$t)+[0,'00'];
        return sprintf('%02d:%02d:00',(int)$h,(int)$m);
    }
    private static function next_cron(string $expr): string {
        $p=preg_split('/\s+/',trim($expr));
        if(count($p)<5)return gmdate('Y-m-d H:i:s',time()+HOUR_IN_SECONDS);
        [$min,$hour]=$p;
        $tm=is_numeric($min)?(int)$min:0;
        $th=is_numeric($hour)?(int)$hour:null;
        $now=time();
        $ts=strtotime(gmdate('Y-m-d',$now).' '.($th!==null?sprintf('%02d:%02d:00',$th,$tm):'00:00:00'),$now);
        if($ts<=$now)$ts+=DAY_IN_SECONDS;
        return gmdate('Y-m-d H:i:s',$ts);
    }
    public static function timezone_options(): array {
        $z=[];
        foreach(timezone_identifiers_list() as $tz)$z[explode('/',$tz)[0]][]=$tz;
        return $z;
    }
    public static function time_presets(): array {
        return ['12:00am','1:00am','2:00am','3:00am','4:00am','5:00am','6:00am','7:00am','8:00am','9:00am','10:00am','11:00am','12:00pm','1:00pm','2:00pm','3:00pm','4:00pm','5:00pm','6:00pm','7:00pm','8:00pm','9:00pm','10:00pm','11:00pm'];
    }
}
