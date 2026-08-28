<?php

declare(strict_types=1);

final class RepresentationExpiryPolicy
{
    public const EXPIRY_HOUR=10;

    public static function timezone():DateTimeZone{return new DateTimeZone('Europe/Berlin');}

    public static function expiresAt(string$date):DateTimeImmutable
    {
        $value=DateTimeImmutable::createFromFormat('!Y-m-d',$date,self::timezone());
        if(!$value||$value->format('Y-m-d')!==$date)throw new InvalidArgumentException('Das Vertretungsdatum ist ungültig.');
        return$value->setTime(self::EXPIRY_HOUR,0,0);
    }

    public static function isActive(string$date,?DateTimeImmutable$now=null):bool
    {
        $now=self::inBerlin($now);return$now<self::expiresAt($date);
    }

    public static function minimumActiveDate(?DateTimeImmutable$now=null):string
    {
        $now=self::inBerlin($now);$today=$now->format('Y-m-d');return self::isActive($today,$now)?$today:$now->modify('+1 day')->format('Y-m-d');
    }

    private static function inBerlin(?DateTimeImmutable$now):DateTimeImmutable{return($now??new DateTimeImmutable('now',self::timezone()))->setTimezone(self::timezone());}
}
