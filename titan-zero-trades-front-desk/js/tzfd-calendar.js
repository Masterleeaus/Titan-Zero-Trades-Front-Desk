/* Server-compiled donor calendar policy. Independently implemented; GPL-2.0-only. */
(function (root) {
    'use strict';
    function parts(epoch, zone) {
        var fixed=/^([+-])(\d{2}):(\d{2})$/.exec(zone);
        if (fixed) {
            var d=new Date((epoch+(fixed[1]==='-'?-1:1)*(+fixed[2]*60 + +fixed[3])*60)*1000);
            return [d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate(),d.getUTCHours(),d.getUTCMinutes(),d.getUTCSeconds()];
        }
        var values={};
        new Intl.DateTimeFormat('en-GB',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(epoch*1000)).forEach(function(p){values[p.type]=+p.value;});
        return [values.year,values.month,values.day,values.hour,values.minute,values.second];
    }
    function frame(p) { return Date.UTC(p[0],p[1]-1,p[2],p[3]||0,p[4]||0,p[5]||0)/1000; }
    function instant(wall, zone) {
        var offsets={},matches=[];
        for(var step=-2;step<=2;step++) { var t=wall+step*86400;offsets[frame(parts(t,zone))-t]=true; }
        Object.keys(offsets).forEach(function(offset){var candidate=wall-Number(offset);if(frame(parts(candidate,zone))===wall){matches.push(candidate);}});
        return matches.length===1?matches[0]:null;
    }
    function allows(policy,date,start,end,index) {
        try {
            if(!policy||policy.version!==1||!policy.services[index]||!/^\d{4}-\d{2}-\d{2}$/.test(date)) { return false; }
            var p=date.split('-').map(Number),day=frame(p),d=new Date(day*1000),s=policy.services[index];
            if(d.toISOString().slice(0,10)!==date||start<0||start>=1440||end-start!==s.duration){return false;}
            var special=[],weekday=[],all=[];
            policy.schedules[s.schedule].forEach(function(r){
                if(r.dates&&date>=r.dates[0]&&date<=r.dates[1]){special.push(r);}
                else if(r.type==='d'+d.getUTCDay()){weekday.push(r);}else if(r.type==='all'){all.push(r);}
            });
            if(!special.length&&(!policy.working[d.getUTCDay()]||policy.closed.some(function(r){return date>=r[0]&&date<=r[1];}))){return false;}
            var rules=special.length?special:(weekday.length?weekday:all);
            if(!rules.some(function(r){return start-s.before>=r.start&&end+s.after<=r.end;})){return false;}
            var a=instant(day+start*60,policy.zone),b=instant(day+end*60,policy.zone);
            return a!==null&&b!==null&&b-a===s.duration*60&&(policy.min===null||a>=policy.min)&&(policy.max===null||b<=policy.max);
        } catch(error) { return false; }
    }
    root.TZFDFrontDeskCalendar={allows:allows,parts:parts,instant:instant};
    if(typeof module!=='undefined'&&module.exports){module.exports=root.TZFDFrontDeskCalendar;}
}(typeof window!=='undefined'?window:globalThis));
