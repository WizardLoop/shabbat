<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Shabbat\Tests;
use Amp\Cancellation;
use Shabbat\Http\JsonClient;
/** Synthetic provider fixtures: solar times are controlled test values, never published schedules. */
final class CalendarFixtureClient implements JsonClient
{
    public array $calls=[];
    public ?\Closure $override=null;
    public function get(string $url,array $query=[],?Cancellation $cancellation=null):array
    {
        $cancellation?->throwIfRequested(); $this->calls[]=[$url,$query];
        if($this->override!==null) { return ($this->override)($url,$query); }
        $start=$query['start']; $end=$query['end'];
        if(str_ends_with($url,'/hebcal')) {
            $items=[];
            for($day=new \DateTimeImmutable($start,new \DateTimeZone('UTC'));$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')) {
                $date=$day->format('Y-m-d');
                $items[]=['date'=>$date,'category'=>'hebdate','hebrew'=>'תאריך בדיקה','hdate'=>'fixture Hebrew date'];
                $items[]=['date'=>$date,'category'=>'dafyomi','title'=>'Berakhot '.((int)$day->format('j')+2),'hebrew'=>'ברכות '.((int)$day->format('j')+2),'link'=>'https://www.sefaria.org/Berakhot.'.((int)$day->format('j')+2).'a'];
                $count=(int)(new \DateTimeImmutable('2026-04-03',new \DateTimeZone('UTC')))->diff($day)->format('%r%a')+1;
                if($count>=1 && $count<=49) { $items[]=['date'=>$date,'category'=>'omer','title_orig'=>'Omer '.$count,'title'=>'Omer '.$count,'hdate'=>'fixture Omer Hebrew date']; }
                if($date==='2026-05-22' || ($date==='2026-05-23' && $query['i']==='off')) {
                    $items[]=['date'=>$date,'category'=>'holiday','yomtov'=>true,'title_orig'=>'Shavuot','title'=>'Shavuot','hebrew'=>'שבועות'];
                }
                if($date==='2019-08-11') { $items[]=['date'=>$date,'category'=>'holiday','title_orig'=>"Tish'a B'Av (observed)",'title'=>"Tish'a B'Av (observed)"]; }
                if(in_array($date,['2026-04-04','2026-04-05','2026-04-06','2026-04-07'],true)) { $items[]=['date'=>$date,'category'=>'holiday','title_orig'=>"Pesach (CH'M)",'title'=>'Pesach intermediate day']; }
            }
            return ['range'=>['start'=>$start,'end'=>$end],'items'=>$items];
        }
        $zone=new \DateTimeZone($query['tzid']); $times=[];
        $offset=(float)$query['latitude']>32?10:0;
        foreach(['alotHaShachar'=>'05:00','misheyakir'=>'05:45','sunrise'=>'06:00','chatzot'=>'12:00','minchaGedola'=>'12:30','sunset'=>'18:00','tzeit7083deg'=>'18:35','tzeit85deg'=>'18:40'] as $name=>$time) {
            for($day=new \DateTimeImmutable($start,new \DateTimeZone('UTC'));$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')) {
                $date=$day->format('Y-m-d'); $instant=new \DateTimeImmutable($date.' '.$time,$zone);
                if(in_array($name,['sunset','tzeit7083deg','tzeit85deg'],true)) { $instant=$instant->modify('+'.$offset.' minutes'); }
                $times[$name][$date]=$instant->format('Y-m-d\TH:i:sP');
            }
        }
        return ['date'=>['start'=>$start,'end'=>$end],'location'=>['tzid'=>$query['tzid']],'times'=>$times];
    }
}
