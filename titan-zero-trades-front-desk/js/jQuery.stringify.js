/*
 * Origin: jQuery.stringify helper, copied from Appointment Hour Booking 1.5.98.
 *   Upstream file: js/jQuery.stringify.js in appointment-hour-booking.1.5.98.zip
 *   from wordpress.org (SHA-256 754d42504b19c3df8e4405afb139c66811b785dd833e89f30a96bdf5ba174bc4).
 *   Author: CodePeople. Project: https://wordpress.org/plugins/appointment-hour-booking/
 *   Licence: GPLv2 (LICENSE.txt), the same as the plugin. The upstream plugin header and
 *   readme.txt declare "GPLv2"; the upstream file itself carries no licence notice.
 *   Probable lineage, not stated by upstream: the serialiser core is close to the SitePoint
 *   json-serialization.js example (Craig Buckler, no licence stated). The escape table matches
 *   json2.js (Douglas Crockford, whose header reads "Public Domain"). See THIRD-PARTY-NOTICES.txt.
 * Local changes: the jQuery alias is renamed from fbuilderjQuery to tzfdformjQuery
 *   (three lines: the alias line, the ready call and the closing argument).
 *   The function name stringifyXX and the code are upstream's, not local changes.
 */
tzfdformjQuery = (typeof tzfdformjQuery != 'undefined' ) ? tzfdformjQuery : jQuery;

tzfdformjQuery(function(){
    (function($) {
        $.extend({
            
            stringifyXX  : function stringifyXX(obj) {
                var enc  = function(param) {
                    var escapable = /[\\\"\x00-\x1f\x7f-\x9f\u00ad\u0600-\u0604\u070f\u17b4\u17b5\u200c-\u200f\u2028-\u202f\u2060-\u206f\ufeff\ufff0-\uffff]/g,
                        meta = {
                            '\b' : '\\b',
                            '\t' : '\\t',
                            '\n' : '\\n',
                            '\f' : '\\f',
                            '\r' : '\\r',
                            '"' : '\\"',
                            '\\' : '\\\\'
                        };
                                        
                    escapable.lastIndex = 0;
                    return escapable.test(param) ? param.replace(escapable, function (a) {
                        var c = meta[a];
                        return typeof c === 'string' ? c : '\\u' + ('0000' + a.charCodeAt(0).toString(16)).slice(-4);
                    }) : param;
                };
                
                var t = typeof (obj);
                if (t != "object" || obj === null) {
                    // simple data type
                    if (t == "string") obj = '"' + obj + '"';
                    return String(obj);
                } else {
                    // recurse array or object
                    var n, v, json = [], arr = (obj && obj.constructor == Array);

                    for (n in obj) {
                        v = obj[n];
                        t = typeof(v);
                        if (t!="function")
                        {
                            if (t == "string") v = '"' + enc(v) + '"'; else if (t == "object" && v !== null) v = $.stringifyXX(v);
                            json.push((arr ? "" : '"' + n + '":') + String(v));
                        }
                    }
                    return (arr ? "[" : "{") + String(json) + (arr ? "]" : "}");
                }
            }
        });
    })(tzfdformjQuery);
});