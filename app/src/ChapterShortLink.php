<?php

declare(strict_types=1);

final class ChapterShortLink
{
    public static function baseSlug(string $chapterName): string
    {
        $name = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $chapterName));
        $name = trim((string) preg_replace('/(?:^|\s)BNI(?:\s|$)/iu', ' ', $name));
        $name = strtr($name, ['Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($transliterated)) $name = $transliterated;
        }
        $name = strtolower($name);
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');
        return 'bni_' . ($name !== '' ? $name : 'chapter');
    }

    public static function ensure(PDO $database, int $orgId): ?string
    {
        $select = $database->prepare('SELECT org_type,chapter_name,short_link_slug FROM organizations WHERE org_id=:org_id');
        $select->execute([':org_id'=>$orgId]); $row=$select->fetch();
        if (!is_array($row) || ($row['org_type']??null)!=='CHAPTER') return null;
        $existing=trim((string)($row['short_link_slug']??'')); if($existing!=='')return$existing;
        $name=trim((string)($row['chapter_name']??'')); if($name==='')return null;
        $base=self::baseSlug($name);$candidates=[$base,$base.'_'.$orgId];
        foreach($candidates as$slug){
            try{
                $update=$database->prepare("UPDATE organizations SET short_link_slug=:slug WHERE org_id=:org_id AND org_type='CHAPTER' AND (short_link_slug IS NULL OR short_link_slug='')");
                $update->execute([':slug'=>$slug,':org_id'=>$orgId]);
                if($update->rowCount()===1)return$slug;
                $select->execute([':org_id'=>$orgId]);$current=$select->fetch();$saved=trim((string)($current['short_link_slug']??''));if($saved!=='')return$saved;
            }catch(PDOException$exception){if((string)$exception->getCode()!=='23000')throw$exception;}
        }
        throw new RuntimeException('Der Chapter-Kurzlink konnte nicht eindeutig vergeben werden.');
    }

    public static function backfill(PDO $database): int
    {
        $ids=$database->query("SELECT org_id FROM organizations WHERE org_type='CHAPTER' AND NULLIF(TRIM(chapter_name),'') IS NOT NULL AND NULLIF(TRIM(short_link_slug),'') IS NULL ORDER BY org_id")->fetchAll(PDO::FETCH_COLUMN);
        $count=0;foreach($ids as$id)if(self::ensure($database,(int)$id)!==null)$count++;return$count;
    }
}
