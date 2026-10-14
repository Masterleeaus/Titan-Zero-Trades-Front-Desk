<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Validated, vertical-neutral resource catalogue. GPL-2.0-only. */
final class TZFD_Resource_Catalog {
    const MAX_LOCATIONS = 100;
    const MAX_RESOURCES = 200;
    const MAX_BYTES = 262144;

    public static function empty_catalog() {
        return array('schema_version'=>1, 'locations'=>array(), 'resources'=>array());
    }

    public static function decode($tzfd_json) {
        if (!is_string($tzfd_json) || strlen($tzfd_json)>self::MAX_BYTES) {
            throw new InvalidArgumentException('Catalogue must be JSON smaller than 256 KiB.');
        }
        $tzfd_value=json_decode($tzfd_json, true, 16);
        if (JSON_ERROR_NONE!==json_last_error()) throw new InvalidArgumentException('Invalid catalogue JSON.');
        return self::validate($tzfd_value);
    }

    public static function validate($input) {
        if (!is_array($input) || !isset($input['schema_version']) || 1!==$input['schema_version']) {
            throw new InvalidArgumentException('Catalogue schema_version must be integer 1.');
        }
        $locations=self::list_value(isset($input['locations'])?$input['locations']:null,self::MAX_LOCATIONS,'locations');
        $resources=self::list_value(isset($input['resources'])?$input['resources']:null,self::MAX_RESOURCES,'resources');
        $result=self::empty_catalog(); $seen=array();
        foreach ($locations as $location) {
            if (!is_array($location)) throw new InvalidArgumentException('Invalid location record.');
            $id=self::identifier(isset($location['id'])?$location['id']:null);
            if (isset($seen[$id])) throw new InvalidArgumentException('Duplicate location ID.');
            $seen[$id]=true;
            $tz=isset($location['timezone'])?$location['timezone']:null;
            if (!is_string($tz) || in_array($tz,array('leapseconds','tzdata.zi'),true) || !in_array($tz,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)) {
                throw new InvalidArgumentException('Location timezone must be an IANA timezone.');
            }
            try { new DateTimeZone($tz); } catch (Throwable $e) {
                throw new InvalidArgumentException('Unsupported location timezone.');
            }
            $codes=array();
            foreach (self::list_value(isset($location['postcodes'])?$location['postcodes']:array(),500,'postcodes') as $code) {
                $codes[]=self::postcode($code, false);
            }
            $result['locations'][]=array('id'=>$id,'name'=>self::name(isset($location['name'])?$location['name']:null),
                'timezone'=>$tz,'enabled'=>self::enabled($location),'postcodes'=>array_values(array_unique($codes)),
                'address'=>self::plain_optional(isset($location['address'])?$location['address']:null,300,'address'),
                'phone'=>self::phone_optional(isset($location['phone'])?$location['phone']:null));
        }
        $ids=array();
        foreach ($resources as $resource) {
            if (!is_array($resource)) throw new InvalidArgumentException('Invalid resource record.');
            $id=self::identifier(isset($resource['id'])?$resource['id']:null);
            if (isset($ids[$id])) throw new InvalidArgumentException('Duplicate resource ID.');
            $ids[$id]=true;
            $location=self::identifier(isset($resource['location_id'])?$resource['location_id']:null);
            if (!isset($seen[$location])) throw new InvalidArgumentException('Resource references an unknown location.');
            $kind=isset($resource['kind'])?$resource['kind']:null;
            if (!in_array($kind,array('provider','equipment','room'),true)) throw new InvalidArgumentException('Invalid resource kind.');
            $services=array();
            foreach(self::list_value(isset($resource['service_ids'])?$resource['service_ids']:array(),100,'service_ids') as $service) {
                $services[]=self::identifier($service);
            }
            $tzfd_weekly=array();
            foreach(self::list_value(isset($resource['weekly'])?$resource['weekly']:array(),56,'weekly') as $window) {
                if (!is_array($window)) throw new InvalidArgumentException('Invalid opening window.');
                $day=self::integer(isset($window['day'])?$window['day']:null,1,7,'weekday');
                $start=self::clock(isset($window['start'])?$window['start']:null,false);
                $end=self::clock(isset($window['end'])?$window['end']:null,true);
                if ($start===$end) throw new InvalidArgumentException('Opening and closing must differ; use 00:00 to 24:00 for a full day.');
                $tzfd_weekly[]=array('day'=>$day,'start'=>$window['start'],'end'=>$window['end']);
            }
            $blackouts=array();
            foreach(self::list_value(isset($resource['blackouts'])?$resource['blackouts']:array(),366,'blackouts') as $tzfd_date) {
                $blackouts[]=self::date($tzfd_date);
            }
            $result['resources'][]=array('id'=>$id,'name'=>self::name(isset($resource['name'])?$resource['name']:null),
                'kind'=>$kind,'location_id'=>$location,'enabled'=>self::enabled($resource),
                'capacity'=>self::integer(isset($resource['capacity'])?$resource['capacity']:null,1,10000,'capacity'),
                'service_ids'=>array_values(array_unique($services)),
                'buffer_before'=>self::integer(isset($resource['buffer_before'])?$resource['buffer_before']:0,0,1440,'buffer_before'),
                'buffer_after'=>self::integer(isset($resource['buffer_after'])?$resource['buffer_after']:0,0,1440,'buffer_after'),
                'weekly'=>$tzfd_weekly,'blackouts'=>array_values(array_unique($blackouts)),
                'role'=>self::plain_optional(isset($resource['role'])?$resource['role']:null,80,'role'),
                'phone'=>self::phone_optional(isset($resource['phone'])?$resource['phone']:null),
                'email'=>self::email_optional(isset($resource['email'])?$resource['email']:null),
                'notes'=>self::plain_optional(isset($resource['notes'])?$resource['notes']:null,1000,'notes'));
        }
        $encoded=wp_json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if (false===$encoded || strlen($encoded)>self::MAX_BYTES) {
            throw new InvalidArgumentException('Canonical catalogue exceeds the 256 KiB import/export limit.');
        }
        return $result;
    }

    public static function identifier($tzfd_value) {
        if (!is_string($tzfd_value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D',$tzfd_value)) {
            throw new InvalidArgumentException('IDs must contain 1 to 64 letters, digits, dots, underscores, colons or hyphens.');
        }
        return $tzfd_value;
    }

    public static function integer($tzfd_value,$min,$max,$field) {
        if (!is_int($tzfd_value) || $tzfd_value<$min || $tzfd_value>$max) throw new InvalidArgumentException('Invalid integer value.');
        return $tzfd_value;
    }

    public static function list_value($tzfd_value,$max,$field) {
        if (!is_array($tzfd_value) || count($tzfd_value)>$max || ($tzfd_value && array_keys($tzfd_value)!==range(0,count($tzfd_value)-1))) {
            throw new InvalidArgumentException('Invalid or oversized list.');
        }
        return $tzfd_value;
    }

    public static function postcode($tzfd_value,$allow_empty=true) {
        if (!is_string($tzfd_value) || strlen($tzfd_value)>16) throw new InvalidArgumentException('Invalid postcode.');
        $tzfd_value=strtoupper(trim($tzfd_value));
        if ($allow_empty && ''===$tzfd_value) return '';
        if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{0,15}$/D',$tzfd_value)) throw new InvalidArgumentException('Postcodes must be exact codes, not wildcards.');
        return str_replace(array(' ','-'),'',$tzfd_value);
    }

    public static function clock($tzfd_value,$closing=false) {
        if ($closing && '24:00'===$tzfd_value) return 1440;
        if (!is_string($tzfd_value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$tzfd_value)) throw new InvalidArgumentException('Invalid HH:MM opening time.');
        return (int)substr($tzfd_value,0,2)*60+(int)substr($tzfd_value,3,2);
    }

    public static function date($tzfd_value) {
        if (!is_string($tzfd_value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$tzfd_value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) {
            throw new InvalidArgumentException('Invalid YYYY-MM-DD date.');
        }
        return $tzfd_value;
    }

    private static function name($tzfd_value) {
        if (!is_string($tzfd_value) || ''===trim($tzfd_value) || strlen($tzfd_value)>160 || !preg_match('//u',$tzfd_value) || preg_match('/[<>\x00-\x1F\x7F]/',$tzfd_value)) {
            throw new InvalidArgumentException('Names must be plain text, 1 to 160 bytes.');
        }
        return trim($tzfd_value);
    }

    /** Optional plain text (tabs and line breaks allowed, no markup). Missing or empty gives ''. */
    public static function plain_optional($tzfd_value,$max,$field) {
        if (null===$tzfd_value || ''===$tzfd_value) return '';
        if (!is_string($tzfd_value) || strlen($tzfd_value)>$max || !preg_match('//u',$tzfd_value) || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$tzfd_value)) {
            throw new InvalidArgumentException( esc_html( (string) $field ).' must be plain text, up to '.(int) $max.' bytes.');
        }
        return trim($tzfd_value);
    }

    /** Optional phone number: digits, spaces and + ( ) - . only. */
    public static function phone_optional($tzfd_value) {
        if (null===$tzfd_value || ''===$tzfd_value) return '';
        if (!is_string($tzfd_value) || !preg_match('/^\+?[0-9 ()\-.]{4,40}$/D',trim($tzfd_value))) throw new InvalidArgumentException('Phone numbers may contain digits, spaces and + ( ) - . only.');
        return trim($tzfd_value);
    }

    /** Optional email address. */
    public static function email_optional($tzfd_value) {
        if (null===$tzfd_value || ''===$tzfd_value) return '';
        if (!is_string($tzfd_value) || strlen($tzfd_value)>190 || false===filter_var(trim($tzfd_value),FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address or leave it empty.');
        return trim($tzfd_value);
    }

    private static function enabled($record) {
        if (!isset($record['enabled']) || !is_bool($record['enabled'])) throw new InvalidArgumentException('enabled must be a JSON boolean.');
        return $record['enabled'];
    }
}
